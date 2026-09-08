<?php

declare(strict_types=1);

namespace App\Tests\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoTaskTest extends TestCase
{
    private const string CREATED = '2026-01-02T03:04:05+00:00';
    private const string LATER = '2026-01-02T03:09:05+00:00';

    public function testANewTaskIsPendingAndHasNothingToShow(): void
    {
        $task = $this->pendingTask();

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertNull($task->finalVideoUrl());
        self::assertNull($task->errorMessage());
        self::assertFalse($task->isSettled());
        self::assertTrue($task->createdAt()->equals($task->updatedAt()));
    }

    public function testThePayloadIsKept(): void
    {
        $payload = ['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]];

        self::assertSame($payload, VideoTask::create($payload, $this->at(self::CREATED))->payload());
    }

    public function testClaimingMovesItToProcessingAndStampsIt(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::PROCESSING, $task->status());
        self::assertSame(self::LATER, $task->updatedAt()->toIso8601());
        self::assertSame(self::CREATED, $task->createdAt()->toIso8601());
    }

    /** The same worker re-claiming its own task is a refreshed lease, not an error. */
    public function testProcessingIsIdempotent(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::LATER));
        $task->markProcessing($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::PROCESSING, $task->status());
    }

    /**
     * What made the twenty configured retries unreachable: a task that stays
     * "processing" between attempts can never be claimed again.
     */
    public function testAFailedAttemptCanHandTheTaskBack(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->markPending($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertSame(self::LATER, $task->updatedAt()->toIso8601());
    }

    public function testCompletingRecordsTheVideoUrl(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->markCompleted('http://localhost/videos/final.mp4', $this->at(self::LATER));

        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
        self::assertSame('http://localhost/videos/final.mp4', $task->finalVideoUrl());
        self::assertTrue($task->isSettled());
    }

    /**
     * The dead-letter queue exists so an operator can requeue a task once the
     * cause is fixed; a terminal status nothing can leave would make
     * `messenger:failed:retry` a no-op.
     */
    public function testAFailedTaskCanBeRequeuedAndSucceedOnTheNextAttempt(): void
    {
        $task = $this->pendingTask();
        $task->markFailed('la descarga falló', $this->at(self::CREATED));

        $task->markProcessing($this->at(self::LATER));
        $task->markCompleted('http://localhost/videos/final.mp4', $this->at(self::LATER));

        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
        // A task that succeeds must not keep advertising the earlier failure.
        self::assertNull($task->errorMessage());
    }

    public function testFailingRecordsTheReason(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->markFailed('no se pudo componer', $this->at(self::LATER));

        self::assertSame(VideoTaskStatus::FAILED, $task->status());
        self::assertSame('no se pudo componer', $task->errorMessage());
        self::assertTrue($task->isSettled());
    }

    public function testACompletedTaskCannotBeReopened(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->markCompleted('http://localhost/videos/final.mp4', $this->at(self::LATER));

        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/"completed" -> "processing"/');

        $task->markProcessing($this->at(self::LATER));
    }

    public function testACompletedTaskCannotBeFailed(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->markCompleted('http://localhost/videos/final.mp4', $this->at(self::LATER));

        $this->expectException(InvalidTaskTransition::class);

        $task->markFailed('too late', $this->at(self::LATER));
    }

    public function testAPendingTaskCannotBeCompletedWithoutBeingClaimed(): void
    {
        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/"pending" -> "completed"/');

        $this->pendingTask()->markCompleted('http://localhost/videos/final.mp4', $this->at(self::LATER));
    }

    /**
     * The listener that writes the terminal status runs after the handler has
     * already handed the claim back, so it sees a pending task.
     */
    public function testAReleasedTaskCanStillBeFailed(): void
    {
        $task = $this->pendingTask();
        $task->markFailed('retries exhausted', $this->at(self::LATER));

        self::assertSame(VideoTaskStatus::FAILED, $task->status());
    }

    public function testAPendingTaskCanBeCanceled(): void
    {
        $task = $this->pendingTask();
        $task->cancel($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertTrue($task->isCanceled());
        self::assertTrue($task->isSettled());
        self::assertSame(self::LATER, $task->updatedAt()->toIso8601());
    }

    public function testAProcessingTaskCanBeCanceled(): void
    {
        $task = $this->pendingTask();
        $task->markProcessing($this->at(self::CREATED));
        $task->cancel($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
    }

    /** @return iterable<string, array{callable(VideoTask): void}> */
    public static function settledTasks(): iterable
    {
        $created = DateTimeValue::fromString(self::CREATED);

        yield 'completed' => [static function (VideoTask $task) use ($created): void {
            $task->markProcessing($created);
            $task->markCompleted('http://localhost/videos/final.mp4', $created);
        }];
        yield 'failed' => [static function (VideoTask $task) use ($created): void {
            $task->markFailed('boom', $created);
        }];
        yield 'canceled' => [static function (VideoTask $task) use ($created): void {
            $task->cancel($created);
        }];
    }

    /** @param callable(VideoTask): void $settle */
    #[DataProvider('settledTasks')]
    public function testASettledTaskCannotBeCanceled(callable $settle): void
    {
        $task = $this->pendingTask();
        $settle($task);

        $this->expectException(InvalidTaskTransition::class);

        $task->cancel($this->at(self::LATER));
    }

    /** A redelivered message must not resurrect a task somebody stopped. */
    public function testACanceledTaskCannotBeClaimed(): void
    {
        $task = $this->pendingTask();
        $task->cancel($this->at(self::CREATED));

        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/"canceled" -> "processing"/');

        $task->markProcessing($this->at(self::LATER));
    }

    /** Exhausted retries of the attempt that was stopped are not a failure of the task. */
    public function testACanceledTaskCannotBeFailed(): void
    {
        $task = $this->pendingTask();
        $task->cancel($this->at(self::CREATED));

        $this->expectException(InvalidTaskTransition::class);

        $task->markFailed('too late', $this->at(self::LATER));
    }

    public function testAFailedTaskCanBeRetriedAndForgetsItsReason(): void
    {
        $task = $this->pendingTask();
        $task->markFailed('la descarga falló', $this->at(self::CREATED));
        $task->retry($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertNull($task->errorMessage());
        self::assertFalse($task->isSettled());
        self::assertSame(self::LATER, $task->updatedAt()->toIso8601());
    }

    public function testACanceledTaskCanBeRetried(): void
    {
        $task = $this->pendingTask();
        $task->cancel($this->at(self::CREATED));
        $task->retry($this->at(self::LATER));

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
    }

    /** @return iterable<string, array{callable(VideoTask): void, string}> */
    public static function tasksThatCannotBeRetried(): iterable
    {
        $created = DateTimeValue::fromString(self::CREATED);

        yield 'pending' => [static function (VideoTask $task): void {}, '"pending" -> "pending"'];
        yield 'processing' => [static function (VideoTask $task) use ($created): void {
            $task->markProcessing($created);
        }, '"processing" -> "pending"'];
        yield 'completed' => [static function (VideoTask $task) use ($created): void {
            $task->markProcessing($created);
            $task->markCompleted('http://localhost/videos/final.mp4', $created);
        }, '"completed" -> "pending"'];
    }

    /** @param callable(VideoTask): void $arrange */
    #[DataProvider('tasksThatCannotBeRetried')]
    public function testOnlyAFailedOrCanceledTaskCanBeRetried(callable $arrange, string $transition): void
    {
        $task = $this->pendingTask();
        $arrange($task);

        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($transition, '/').'/');

        $task->retry($this->at(self::LATER));
    }

    public function testRehydrationRestoresEveryField(): void
    {
        $task = VideoTask::rehydrate(
            UuidValue::fromString('0195c6a0-1c37-7000-8000-000000000000'),
            ['images' => []],
            VideoTaskStatus::COMPLETED,
            'http://localhost/videos/final.mp4',
            null,
            $this->at(self::CREATED),
            $this->at(self::LATER),
        );

        self::assertSame('0195c6a0-1c37-7000-8000-000000000000', $task->id()->value);
        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
        self::assertSame('http://localhost/videos/final.mp4', $task->finalVideoUrl());
        self::assertSame(self::CREATED, $task->createdAt()->toIso8601());
        self::assertSame(self::LATER, $task->updatedAt()->toIso8601());
    }

    private function pendingTask(): VideoTask
    {
        return VideoTask::create(['images' => []], $this->at(self::CREATED));
    }

    private function at(string $iso8601): DateTimeValue
    {
        return DateTimeValue::fromString($iso8601);
    }
}
