<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Command;

use App\Task\Application\Command\CreateVideoTaskCommand;
use App\Task\Application\Command\CreateVideoTaskHandler;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use App\Tests\Support\SpyTransaction;
use PHPUnit\Framework\TestCase;

final class CreateVideoTaskHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private SpyTransaction $transaction;
    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->transaction = new SpyTransaction();
        $this->bus = new RecordingMessageBus($this->transaction);
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

    private function handler(): CreateVideoTaskHandler
    {
        return new CreateVideoTaskHandler(
            $this->tasks,
            $this->partials,
            new FixedClock(),
            $this->transaction,
            $this->bus,
        );
    }
}
