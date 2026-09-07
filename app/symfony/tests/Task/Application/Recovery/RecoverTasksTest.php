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

    /**
     * The same rule the callback sweep already followed, on the other branch.
     * A task waiting its turn in a busy broker is still pending and still
     * untouched, so every run of the documented cron published another copy of
     * a message that is not lost at all - a backlog of long renders grew a
     * queue of no-ops behind it, delaying the very work it was meant to
     * recover.
     */
    public function testATaskThisSweepAlreadyPublishedIsNotPublishedAgain(): void
    {
        $task = $this->pendingTask('2026-03-01T09:00:00+00:00');

        self::assertSame([$task->id()->value], $this->recover()->run($this->cutoff())->requeuedTaskIds);

        // The next run, with the message still queued behind the backlog.
        $this->bus->dispatched = [];
        self::assertSame([], $this->recover()->run($this->cutoff())->requeuedTaskIds);
        self::assertSame([], $this->bus->dispatched);
    }

    /** A dry run reports what a real one would do, and must leave it able to. */
    public function testADryRunClaimsNothing(): void
    {
        $task = $this->pendingTask('2026-03-01T09:00:00+00:00');

        self::assertSame([$task->id()->value], $this->recover()->run($this->cutoff(), dryRun: true)->requeuedTaskIds);
        self::assertSame([], $this->bus->dispatched);

        self::assertSame([$task->id()->value], $this->recover()->run($this->cutoff())->requeuedTaskIds);
        self::assertEquals([new ProcessVideoTaskCommand($task->id()->value)], $this->bus->dispatched);
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
        self::assertEquals([new NotifyTaskCallback($task->id()->value, 'completed', $task->runGeneration())], $this->bus->dispatched);
    }

    /**
     * The two kinds are recovered on separately configured transports, and one
     * being unreachable says nothing about the other.
     *
     * Thrown out of the loop, the first task whose publish failed ended the run
     * before the callbacks were even looked at - and a stale pending task stays
     * stale until it is republished, so healthy notifications went unrecovered
     * for as long as the fault lasted, which is exactly when they are most
     * likely to be owed.
     */
    public function testAFailedTaskPublishStillLetsTheCallbacksBeRecovered(): void
    {
        $this->pendingTask('2026-03-01T09:00:00+00:00');
        $owed = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');

        $this->bus->failFor(ProcessVideoTaskCommand::class, new \RuntimeException('el broker no responde'));

        $report = $this->recover()->run($this->cutoff());

        self::assertSame([], $report->requeuedTaskIds, 'the task was not published, and is not reported as if it were');
        self::assertSame([$owed->id()->value], $report->renotifiedTaskIds);
        self::assertContainsEquals(
            new NotifyTaskCallback($owed->id()->value, 'completed', $owed->runGeneration()),
            $this->bus->dispatched,
        );
    }

    public function testACallbackAlreadyDeliveredIsNotSentTwice(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');
        $this->tasks->markCallbackNotified($task->id(), $task->status()->value, $task->runGeneration(), DateTimeValue::fromString('2026-03-01T09:00:01+00:00'));

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

    /**
     * A notification nothing can deliver is not one that was lost. The sweep
     * exists for a publish that never reached the broker; a callback URL the
     * guard refuses is refused on every attempt, and a deployment with no
     * signing secret signs nothing, so offering that task again only produces
     * the same refusal and another entry in the failure transport - once per
     * run, for the life of the task.
     */
    public function testACallbackGivenUpOnIsNeverOfferedAgain(): void
    {
        $task = $this->settledTask('2026-03-01T09:00:00+00:00', 'https://client.example/hook');
        $this->tasks->markCallbackAbandoned($task->id(), $task->status()->value, $task->runGeneration(), DateTimeValue::fromString('2026-03-01T09:00:01+00:00'));

        self::assertSame([], $this->recover()->run($this->cutoff())->renotifiedTaskIds);

        // And not an hour later either, when the sweep's own attempt has aged
        // past the window: that is what makes it forever rather than once.
        $later = DateTimeValue::fromString('2026-03-01T11:00:00+00:00')->minusSeconds(600);
        $recover = new RecoverTasks($this->tasks, $this->bus, new FixedClock('2026-03-01T11:00:00+00:00'), new NullLogger());

        self::assertSame([], $recover->run($later)->renotifiedTaskIds);
        self::assertSame([], $this->bus->dispatched);
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
