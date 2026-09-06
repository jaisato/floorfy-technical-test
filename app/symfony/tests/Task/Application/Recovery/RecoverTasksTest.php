<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Recovery;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Application\Recovery\RecoverTasks;
use App\Task\Domain\Entity\VideoTask;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The messages the broker never got.
 *
 * A task row and the message announcing it live in two systems, so writing one
 * cannot be part of committing the other: the message goes out right after the
 * commit, and a process dying in that gap leaves a task nobody will process,
 * or a settled task whose client is never told. This is what finds them.
 */
final class RecoverTasksTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->bus = new RecordingMessageBus();
    }

    public function testAPendingTaskLeftBehindIsPublishedAgain(): void
    {
        $task = $this->pendingTask('2026-03-01T09:00:00+00:00');

        $report = $this->recover()->run($this->cutoff());

        self::assertSame([$task->id()->value], $report->requeuedTaskIds);
        self::assertEquals([new ProcessVideoTaskCommand($task->id()->value)], $this->bus->dispatched);
    }

    /**
     * A task published a moment ago is not stuck, it is new. Re-publishing it
     * would only have two workers race for a claim one of them must lose.
     */
    public function testATaskInsideTheGraceWindowIsLeftAlone(): void
    {
        $this->pendingTask('2026-03-01T09:59:00+00:00');

        $report = $this->recover()->run($this->cutoff());

        self::assertSame([], $report->requeuedTaskIds);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testATaskAlreadyBeingProcessedIsNotDisturbed(): void
    {
        $task = $this->pendingTask('2026-03-01T09:00:00+00:00');
        $task->markProcessing(DateTimeValue::fromString('2026-03-01T09:00:00+00:00'));

        self::assertSame([], $this->recover()->run($this->cutoff())->requeuedTaskIds);
    }

    public function testASettledTaskWhoseCallbackWasNeverDeliveredIsQueuedAgain(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');

        $report = $this->recover()->run($this->cutoff());

        self::assertSame([$task->id()->value], $report->renotifiedTaskIds);
        self::assertEquals([new NotifyTaskCallback($task->id()->value, 'completed')], $this->bus->dispatched);
    }

    public function testACallbackAlreadyDeliveredIsNotSentTwice(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');
        $this->tasks->markCallbackNotified($task->id(), DateTimeValue::fromString('2026-03-01T09:00:01+00:00'));

        self::assertSame([], $this->recover()->run($this->cutoff())->renotifiedTaskIds);
    }

    /**
     * A notification the sweep published a moment ago is not lost: it is on
     * its way, or the transport is retrying it. Neither stamps
     * callback_notified_at, so every run in between published another one and
     * the client got the same POST as many times as the sweep ran.
     */
    public function testANotificationThisSweepAlreadyPublishedIsNotPublishedAgain(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');

        self::assertSame([$task->id()->value], $this->recover()->run($this->cutoff())->renotifiedTaskIds);

        // The next run, with the delivery still in flight.
        $this->bus->dispatched = [];
        self::assertSame([], $this->recover()->run($this->cutoff())->renotifiedTaskIds);
        self::assertSame([], $this->bus->dispatched);
    }

    /** Once the attempt is itself older than the cutoff, it was lost after all. */
    public function testANotificationWhoseAttemptIsOlderThanTheCutoffIsPublishedAgain(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');
        $this->recover()->run($this->cutoff());
        $this->bus->dispatched = [];

        // An hour later: the attempt at 10:00 is now well before the window.
        $later = DateTimeValue::fromString('2026-03-01T11:00:00+00:00')->minusSeconds(600);
        $recover = new RecoverTasks($this->tasks, $this->bus, new FixedClock('2026-03-01T11:00:00+00:00'), new NullLogger());

        self::assertSame([$task->id()->value], $recover->run($later)->renotifiedTaskIds);
    }

    /** A dry run must not take the claim it is only reporting on. */
    public function testADryRunLeavesTheNotificationForARealRunToTake(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');

        self::assertSame(1, $this->recover()->run($this->cutoff(), dryRun: true)->renotified());
        self::assertSame([$task->id()->value], $this->recover()->run($this->cutoff())->renotifiedTaskIds);
    }

    public function testATaskThatAskedForNoCallbackIsNeverOffered(): void
    {
        $this->settledTask('2026-03-01T09:00:00+00:00', null);

        self::assertSame([], $this->recover()->run($this->cutoff())->renotifiedTaskIds);
    }

    /** The point of --dry-run: find out whether anything is being lost at all. */
    public function testADryRunReportsWhatItWouldPublishAndPublishesNothing(): void
    {
        $this->pendingTask('2026-03-01T09:00:00+00:00');
        $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');

        $report = $this->recover()->run($this->cutoff(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->requeued());
        self::assertSame(1, $report->renotified());
        self::assertSame([], $this->bus->dispatched);
    }

    public function testNothingToRecoverIsAnEmptyReport(): void
    {
        self::assertTrue($this->recover()->run($this->cutoff())->isEmpty());
    }

    /** One run has a bounded cost whatever the backlog is. */
    public function testTheRunIsCappedAtTheLimit(): void
    {
        $this->pendingTask('2026-03-01T09:00:00+00:00');
        $this->pendingTask('2026-03-01T09:01:00+00:00');
        $this->pendingTask('2026-03-01T09:02:00+00:00');

        self::assertSame(2, $this->recover()->run($this->cutoff(), limit: 2)->requeued());
    }

    private function recover(): RecoverTasks
    {
        return new RecoverTasks($this->tasks, $this->bus, new FixedClock('2026-03-01T10:00:00+00:00'), new NullLogger());
    }

    /** Ten minutes before "now", the window the command defaults to. */
    private function cutoff(): DateTimeValue
    {
        return DateTimeValue::fromString('2026-03-01T10:00:00+00:00')->minusSeconds(600);
    }

    private function pendingTask(string $createdAt): VideoTask
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString($createdAt));
        $this->tasks->save($task);

        return $task;
    }

    private function settledTask(string $finishedAt, ?string $callbackUrl): VideoTask
    {
        $at = DateTimeValue::fromString($finishedAt);
        $task = VideoTask::create(['images' => []], $at, $callbackUrl);
        $task->markProcessing($at);
        $task->markCompleted('/videos/final_'.$task->id()->value.'.mp4', $at);
        $this->tasks->save($task);

        return $task;
    }
}
