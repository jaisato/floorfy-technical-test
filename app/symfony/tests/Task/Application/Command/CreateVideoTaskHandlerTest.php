<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Command;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Command\CreateVideoTaskCommand;
use App\Task\Application\Command\CreateVideoTaskHandler;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingMessageBus;
use App\Tests\Support\SpyTransaction;
use PHPUnit\Framework\TestCase;

final class CreateVideoTaskHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private SpyTransaction $transaction;
    private RecordingMessageBus $bus;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->transaction = new SpyTransaction();
        $this->bus = new RecordingMessageBus($this->transaction);
        $this->logger = new RecordingLogger();
    }

    public function testItStoresThePendingTaskAndOnePartPerImage(): void
    {
        $id = ($this->handler())(new CreateVideoTaskCommand([
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'zoom_in'],
        ]));

        $task = $this->tasks->all()[0];

        self::assertSame($id, $task->id()->value);
        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertCount(2, $this->partials->all());
    }

    /**
     * The concatenation order is the order the client sent, and nothing else:
     * the parts of a task are all created in the same request, so a timestamp
     * cannot separate them.
     */
    public function testPartsAreNumberedInTheOrderTheyWereSent(): void
    {
        ($this->handler())(new CreateVideoTaskCommand([
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'zoom_in'],
            ['url' => 'https://example.com/c.png', 'transition' => 'zoom_out'],
        ]));

        $partials = $this->partials->listByTaskId($this->tasks->all()[0]->id());

        self::assertSame([0, 1, 2], array_map(static fn (PartialVideo $p): int => $p->position(), $partials));
        self::assertSame(
            ['https://example.com/a.png', 'https://example.com/b.png', 'https://example.com/c.png'],
            array_map(static fn (PartialVideo $p): string => $p->imageUrl(), $partials),
        );
        self::assertSame(Transition::ZOOM_OUT, $partials[2]->transition());
    }

    public function testTheStoredPayloadIsTheValidatedImageList(): void
    {
        ($this->handler())(new CreateVideoTaskCommand([
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
        ]));

        self::assertSame(
            ['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]],
            $this->tasks->all()[0]->payload(),
        );
    }

    public function testExactlyOneProcessingMessageIsQueued(): void
    {
        $id = ($this->handler())(new CreateVideoTaskCommand([
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'pan'],
        ]));

        self::assertCount(1, $this->bus->dispatched);
        self::assertInstanceOf(ProcessVideoTaskCommand::class, $this->bus->dispatched[0]);
        self::assertSame($id, $this->bus->dispatched[0]->taskId);
    }

    /**
     * The task and its parts are one unit. Written separately, an error in the
     * middle of the loop leaves a task the worker can never finish.
     */
    public function testTheWholeWriteHappensInsideOneTransaction(): void
    {
        ($this->handler())(new CreateVideoTaskCommand([['url' => 'https://example.com/a.png', 'transition' => 'pan']]));

        self::assertSame(1, $this->transaction->maxDepth);
        self::assertFalse($this->transaction->running);
    }

    /**
     * A message published before the rows are committed can reach a worker that
     * cannot see the task yet, or outlive a transaction that rolled back.
     */
    public function testTheMessageIsPublishedOnlyAfterTheTransactionCommits(): void
    {
        ($this->handler())(new CreateVideoTaskCommand([['url' => 'https://example.com/a.png', 'transition' => 'pan']]));

        self::assertSame([false], $this->bus->insideTransaction);
    }

    /**
     * A broker that refuses the publish does not undo the task.
     *
     * The rows are committed by then, and RecoverTasks looks for exactly this
     * row - pending, untouched - and publishes it again: that sweep is why a
     * lost publish is a delay rather than a loss, and it already covers the
     * process dying one line earlier. Answered 5xx, as this used to be, it was
     * worse than that crash: the idempotency key is released on a retryable
     * status, so the client's retry created a second task with all of its
     * rendering while the sweep published the first.
     */
    public function testABrokerThatRefusesThePublishStillLeavesTheTaskCreated(): void
    {
        $this->bus->failure = new \RuntimeException('AMQPIOException: connection refused');

        $id = ($this->handler())(new CreateVideoTaskCommand([['url' => 'https://example.com/a.png', 'transition' => 'pan']]));

        self::assertNotSame('', $id, 'the caller is given the task it created');
        $task = $this->tasks->get(UuidValue::fromString($id));
        self::assertNotNull($task);
        self::assertSame(VideoTaskStatus::PENDING, $task->status(), 'pending is what the sweep looks for');
        self::assertCount(1, $this->partials->listByTaskId($task->id()));
        self::assertStringContainsString('recovery sweep', $this->logger->everythingLogged());
    }

    /** What the deployment renders with when a task chooses nothing. */
    private static function defaults(): RenderOptions
    {
        return new RenderOptions(3.0, 30, '1280x720', 0.0);
    }

    private function handler(): CreateVideoTaskHandler
    {
        return new CreateVideoTaskHandler(
            $this->tasks,
            $this->partials,
            new FixedClock(),
            $this->transaction,
            $this->bus,
            self::defaults(),
            $this->logger,
        );
    }
}
