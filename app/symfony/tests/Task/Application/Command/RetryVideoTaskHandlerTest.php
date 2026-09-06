<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Command;

use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Application\Command\RetryVideoTaskCommand;
use App\Task\Application\Command\RetryVideoTaskHandler;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use App\Tests\Support\SpyTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryVideoTaskHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private FixedClock $clock;
    private SpyTransaction $transaction;
    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->clock = new FixedClock();
        $this->transaction = new SpyTransaction();
        $this->bus = new RecordingMessageBus($this->transaction);
    }

    public function testAFailedTaskGoesBackToPendingWithoutItsError(): void
    {
        $task = $this->failedTask();

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertNull($task->errorMessage());
        self::assertNull($task->finalVideoUrl());
    }

    /**
     * A task settles once per run and owes the client a notification each time.
     * The delivery mark is one column, and left over from the run before, it
     * told the recovery sweep - which looks for settled tasks whose callback
     * never went out - that this one had already been notified. The second
     * run's notification was then the one publish nothing could ever recover.
     */
    public function testRetryingForgetsThatTheEarlierRunsCallbackWasDelivered(): void
    {
        $task = $this->failedTask();
        $this->tasks->markCallbackNotified($task->id(), $task->status()->value, $this->clock->now());

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertArrayNotHasKey($task->id()->value, $this->tasks->callbacksNotified);
    }

    /**
     * prunedAt says the task's videos were reclaimed, and a task queued for
     * another run is about to have videos again. Left set it both lied to
     * clients and - since prunedAt is what excludes a row from the retention
     * sweep - left the new run's files as the one set nothing would collect.
     */
    public function testRetryingClearsTheRecordThatTheVideosWerePruned(): void
    {
        $task = $this->failedTask();
        $task->markPruned($this->clock->now());

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertNull($task->prunedAt());
    }

    public function testACanceledTaskCanBeRetriedToo(): void
    {
        $task = $this->storedTask();
        $task->cancel($this->clock->now());

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
    }

    /** What the previous attempt paid for is kept; only the rest is redone. */
    public function testFailedPartsAreQueuedAgainAndCompletedOnesAreKept(): void
    {
        $task = $this->failedTask();
        $now = $this->clock->now();

        $done = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $now);
        $done->markCompleted('/videos/partial_a.mp4', $now);
        $broken = PartialVideo::create($task->id(), 'https://example.com/b.png', Transition::PAN, 1, $now);
        $broken->markFailed('la descarga falló', $now);
        $waiting = PartialVideo::create($task->id(), 'https://example.com/c.png', Transition::PAN, 2, $now);
        $this->partials->saveAll([$done, $broken, $waiting]);

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertSame(PartialVideoStatus::COMPLETED, $done->status());
        self::assertSame('/videos/partial_a.mp4', $done->videoPath());
        self::assertSame(PartialVideoStatus::PENDING, $broken->status());
        self::assertSame(PartialVideoStatus::PENDING, $waiting->status());
    }

    public function testExactlyOneProcessingMessageIsQueuedAfterTheCommit(): void
    {
        $task = $this->failedTask();

        $this->handler()(new RetryVideoTaskCommand($task->id()->value));

        self::assertCount(1, $this->bus->dispatched);
        self::assertInstanceOf(ProcessVideoTaskCommand::class, $this->bus->dispatched[0]);
        self::assertSame($task->id()->value, $this->bus->dispatched[0]->taskId);
        self::assertSame([false], $this->bus->insideTransaction);
        self::assertSame(1, $this->transaction->maxDepth);
    }

    /** @return iterable<string, array{callable(VideoTask, FixedClock): void, string}> */
    public static function tasksThatCannotBeRetried(): iterable
    {
        yield 'pending' => [static function (VideoTask $task, FixedClock $clock): void {}, '"pending" -> "pending"'];
        yield 'processing' => [static function (VideoTask $task, FixedClock $clock): void {
            $task->markProcessing($clock->now());
        }, '"processing" -> "pending"'];
        yield 'completed' => [static function (VideoTask $task, FixedClock $clock): void {
            $task->markProcessing($clock->now());
            $task->markCompleted('http://localhost/videos/final.mp4', $clock->now());
        }, '"completed" -> "pending"'];
    }

    /** @param callable(VideoTask, FixedClock): void $arrange */
    #[DataProvider('tasksThatCannotBeRetried')]
    public function testOnlyAFailedOrCanceledTaskCanBeRetried(callable $arrange, string $transition): void
    {
        $task = $this->storedTask();
        $arrange($task, $this->clock);

        try {
            $this->handler()(new RetryVideoTaskCommand($task->id()->value));
            self::fail('the retry should have been refused');
        } catch (InvalidTaskTransition $e) {
            self::assertStringContainsString($transition, $e->getMessage());
        }

        self::assertSame([], $this->bus->dispatched, 'a refused retry must not queue anything');
    }

    public function testAnUnknownTaskIsNotFound(): void
    {
        $this->expectException(TaskNotFound::class);

        $this->handler()(new RetryVideoTaskCommand('0195c6a0-1c37-7000-8000-0000000000ff'));
    }

    /**
     * Two retries of the same task arriving together. Read outside the row
     * lock, both saw "failed", both passed the transition check, and the
     * second flushed its stale aggregate back to pending - over a task the
     * first retry's message had already put into processing, so a second
     * worker could claim it and render into the same files. Read again under
     * the lock, the second sees what the first did and the transition refuses
     * it.
     */
    public function testASecondRetryArrivingDuringTheFirstIsRefused(): void
    {
        $task = $this->failedTask();

        // The window the lock closes: between this retry's read and its write,
        // the first retry has already requeued the task and its worker claimed it.
        $this->tasks->beforeLockedRead = function () use ($task): void {
            $this->tasks->beforeLockedRead = null;
            $task->retry($this->clock->now());
            $this->tasks->claimForProcessing($task->id(), $this->clock->now(), $this->clock->now());
        };

        $this->expectException(InvalidTaskTransition::class);

        try {
            $this->handler()(new RetryVideoTaskCommand($task->id()->value));
        } finally {
            self::assertSame(VideoTaskStatus::PROCESSING, $task->status(), 'the run under way is left alone');
            self::assertSame([], $this->bus->dispatched, 'and no second worker is sent after it');
        }
    }

    private function failedTask(): VideoTask
    {
        $task = $this->storedTask();
        $task->markFailed('No se pudieron generar 1 de 3 vídeos parciales.', $this->clock->now());

        return $task;
    }

    private function storedTask(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->clock->now());
        $this->tasks->save($task);

        return $task;
    }

    private function handler(): RetryVideoTaskHandler
    {
        return new RetryVideoTaskHandler($this->tasks, $this->partials, $this->clock, $this->transaction, $this->bus);
    }
}
