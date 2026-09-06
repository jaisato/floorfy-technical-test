<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Messenger;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\TaskProcessingFailed;
use App\Task\Infrastructure\Messenger\MarkTaskFailedWhenRetriesAreExhausted;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The terminal status is written here and nowhere else: the handler runs once
 * per delivery, so a task it settles on the first error is one the remaining
 * nineteen attempts can no longer touch.
 */
final class MarkTaskFailedWhenRetriesAreExhaustedTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private FixedClock $clock;
    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->clock = new FixedClock();
        $this->bus = new RecordingMessageBus();
    }

    /** The terminal status is the moment the client who asked is told. */
    public function testTheFailureIsNotifiedToTheTasksCallbackUrl(): void
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString('2026-01-02T03:04:05+00:00'), 'https://client.example/hook');
        $this->tasks->save($task);

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom')));

        self::assertCount(1, $this->bus->dispatched);
        self::assertInstanceOf(NotifyTaskCallback::class, $this->bus->dispatched[0]);
        self::assertSame('failed', $this->bus->dispatched[0]->event);
    }

    public function testAnAttemptThatWillBeRetriedNotifiesNobody(): void
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString('2026-01-02T03:04:05+00:00'), 'https://client.example/hook');
        $this->tasks->save($task);

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom'), willRetry: true));

        self::assertSame([], $this->bus->dispatched);
    }

    public function testATaskIsLeftAloneWhileAnotherAttemptIsStillComing(): void
    {
        $task = $this->pendingTask();

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom'), willRetry: true));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
    }

    public function testTheTaskIsMarkedFailedOnceTheRetriesAreExhausted(): void
    {
        $task = $this->pendingTask();

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom')));

        self::assertSame(VideoTaskStatus::FAILED, $task->status());
        self::assertSame(
            'El procesamiento de la tarea no pudo completarse.',
            $task->errorMessage(),
            'the message of an arbitrary exception must not be served to clients',
        );
    }

    /**
     * Messenger wraps what the handler threw, and the handler wraps what a port
     * threw, so the explanation worth keeping is down the chain.
     */
    public function testAReasonWrittenForClientsSurvivesTheWrapping(): void
    {
        $task = $this->pendingTask();
        $cause = TaskProcessingFailed::partialsFailed(1, 3);

        $this->listener()($this->failure(
            $task->id()->value,
            new HandlerFailedException(new Envelope(new ProcessVideoTaskCommand($task->id()->value)), [$cause]),
        ));

        self::assertSame('No se pudieron generar 1 de 3 vídeos parciales.', $task->errorMessage());
    }

    public function testAnUnrecoverableFailureAlsoKeepsItsReason(): void
    {
        $task = $this->pendingTask();
        $cause = TaskProcessingFailed::noPartials();

        $this->listener()($this->failure(
            $task->id()->value,
            new UnrecoverableMessageHandlingException($cause->getMessage(), previous: $cause),
        ));

        self::assertSame(VideoTaskStatus::FAILED, $task->status());
        self::assertSame('La tarea no tiene imágenes que procesar.', $task->errorMessage());
    }

    /** A task that finished on another delivery keeps its result. */
    public function testACompletedTaskIsNotOverwritten(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->clock->now());
        $task->markCompleted('http://localhost/videos/final.mp4', $this->clock->now());

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom')));

        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
        self::assertNull($task->errorMessage());
    }

    /**
     * The attempt that was stopped by a cancellation may still exhaust its
     * retries on the transport; that is not a failure of the task.
     */
    public function testACanceledTaskIsNotOverwritten(): void
    {
        $task = $this->pendingTask();
        $task->cancel($this->clock->now());

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom')));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertNull($task->errorMessage());
        self::assertSame(0, $this->tasks->saves);
    }

    /**
     * The same cancellation, arriving in the window between reading the task
     * and writing the failure. Read-then-save flushed the stale aggregate back
     * as failed: the DELETE had already answered success, the row said failed,
     * and the client was told both - with a callback for each.
     */
    public function testACancellationThatLandsWhileTheFailureIsBeingWrittenWins(): void
    {
        $task = VideoTask::create(
            ['images' => []],
            DateTimeValue::fromString('2026-01-02T03:04:05+00:00'),
            'https://client.example/hook',
        );
        $this->tasks->save($task);
        $clock = $this->clock;
        $this->tasks->beforeMarkFailed = static function () use ($task, $clock): void {
            $task->cancel($clock->now());
        };

        $this->listener()($this->failure($task->id()->value, new \RuntimeException('boom')));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertNull($task->errorMessage());
        self::assertSame([], $this->bus->dispatched, 'no failure callback for a task that was canceled');
    }

    public function testAFailureForAnUnknownTaskIsIgnored(): void
    {
        $this->listener()($this->failure('0195c6a0-1c37-7000-8000-0000000000ff', new \RuntimeException('boom')));

        self::assertSame(0, $this->tasks->saves);
    }

    public function testAFailureCarryingAMalformedIdIsIgnored(): void
    {
        $this->listener()($this->failure('not-a-uuid', new \RuntimeException('boom')));

        self::assertSame(0, $this->tasks->saves);
    }

    public function testFailuresOfOtherMessagesAreNotThisListenersBusiness(): void
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        );

        $this->listener()($event);

        self::assertSame(0, $this->tasks->saves);
    }

    private function pendingTask(): VideoTask
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString('2026-01-02T03:04:05+00:00'));
        $this->tasks->save($task);
        $this->tasks->saves = 0;

        return $task;
    }

    private function failure(string $taskId, \Throwable $cause, bool $willRetry = false): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new ProcessVideoTaskCommand($taskId)),
            'async',
            $cause,
        );

        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function listener(): MarkTaskFailedWhenRetriesAreExhausted
    {
        return new MarkTaskFailedWhenRetriesAreExhausted($this->tasks, $this->clock, new TaskCallbacks($this->bus));
    }
}
