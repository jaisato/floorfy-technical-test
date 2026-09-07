<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Retention;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Retention\PruneVideos;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SpyTransaction;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The retention job: which tasks it touches, what it deletes, and what it
 * leaves behind.
 */
final class PruneVideosTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private FixedClock $clock;
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->clock = new FixedClock('2026-03-01T00:00:00+00:00');
        $this->dir = new TempDirectory('floorfy-prune');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testAnOldFinishedTaskLosesItsVideos(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $final = $this->videoFile('final_'.$task->id()->value.'.mp4');
        $clip = $this->videoFile('partial_'.$part->id()->value.'.mp4');

        $report = $this->prune()->run($this->cutoff());

        self::assertSame([$task->id()->value], $report->taskIds);
        self::assertSame(2, $report->files);
        self::assertFileDoesNotExist($final);
        self::assertFileDoesNotExist($clip);
    }

    /**
     * The row survives on purpose: deleting it would lose the record that the
     * work was done, and let the same request be replayed as new.
     */
    public function testTheTaskStaysWithItsUrlClearedAndPrunedAtSet(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);

        $this->prune()->run($this->cutoff());

        self::assertNotNull($this->tasks->get($task->id()));
        self::assertNull($task->finalVideoUrl());
        self::assertSame($this->clock->now()->toIso8601(), $task->prunedAt()?->toIso8601());
        self::assertNull($part->videoPath());
        // The part was rendered; a retry finds no file and renders it again.
        self::assertTrue($part->isCompleted());
    }

    /**
     * A file that would not go - a read-only mount, a permission the
     * deployment lost - used to be counted as freed and the task marked
     * pruned, so the sweep never came back and the bytes leaked for good while
     * every report said they had gone.
     */
    public function testAFileThatCannotBeDeletedLeavesTheTaskForTheNextRun(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $this->videoFile('final_'.$task->id()->value.'.mp4');

        // Something at the clip's path that unlink() will not remove. A
        // directory is the one thing that refuses even to a test running as
        // root, and the branch under test is simply "unlink said no" - which in
        // a deployment is a read-only mount or a lost permission.
        $clip = $this->dir->file('videos').'/partial_'.$part->id()->value.'.mp4';
        mkdir($clip, 0o755, true);

        $logger = new RecordingLogger();
        $report = $this->prune($logger)->run($this->cutoff());

        self::assertDirectoryExists($clip, 'what would not go is still there');
        self::assertSame(1, $report->files, 'only the file that actually went is counted');
        self::assertSame([], $report->taskIds, 'and the task is not reported as pruned');
        self::assertNull($task->prunedAt(), 'so the next run tries again');
        self::assertStringContainsString('Retention could not delete every file of a task', $logger->everythingLogged());

        rmdir($clip);
    }

    /**
     * The half that did go is gone, and the task went on naming it: the final
     * video was deleted while the clip refused, and GET /api/tasks/{id} kept
     * handing clients a link this very run had deleted - a download that 404s
     * against an API saying the video is there. The pointer comes down; the
     * task itself is untouched, so the next run still finds it and still has
     * the file it could not delete to try again on.
     */
    public function testAPartialRunStopsAdvertisingTheFilesItDidDelete(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $this->videoFile('final_'.$task->id()->value.'.mp4');

        $clip = $this->dir->file('videos').'/partial_'.$part->id()->value.'.mp4';
        mkdir($clip, 0o755, true);

        $updatedAt = $task->updatedAt();
        $this->prune()->run($this->cutoff());

        self::assertNull($task->finalVideoUrl(), 'the video it deleted is not offered any more');
        self::assertNull($task->prunedAt(), 'and the task is not pruned: a file of its own is still there');
        self::assertTrue($updatedAt->equals($task->updatedAt()), 'nor moved out of the next run listing');
        self::assertNotNull($part->videoPath(), 'the clip that would not go still has its path');

        rmdir($clip);
    }

    /** The other way round: the clip goes and the final video refuses. */
    public function testTheClipsPathComesDownWhenItsFileWentAndTheFinalVideoDidNot(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $this->videoFile('partial_'.$part->id()->value.'.mp4');

        $final = $this->dir->file('videos').'/final_'.$task->id()->value.'.mp4';
        mkdir($final, 0o755, true);

        $this->prune()->run($this->cutoff());

        self::assertNull($part->videoPath());
        self::assertNotNull($task->finalVideoUrl(), 'what is still on disk is still offered');
        self::assertNull($task->prunedAt());

        rmdir($final);
    }

    public function testTheScratchDirectoryGoesWithThem(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $work = $this->dir->file('work/images/'.$task->id()->value);
        mkdir($work, 0o775, true);
        file_put_contents($work.'/a.png', 'image');

        $this->prune()->run($this->cutoff());

        self::assertDirectoryDoesNotExist($work);
    }

    /**
     * A scratch directory that will not go keeps the task in the listing.
     *
     * Every failure here used to be suppressed and the task marked pruned
     * anyway, so a work volume gone read-only left the downloaded sources on
     * disk for good: listPrunable() skips a pruned task, and nothing else
     * sweeps that directory.
     */
    public function testAScratchDirectoryThatCannotBeRemovedLeavesTheTaskForTheNextRun(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $this->videoFile('final_'.$task->id()->value.'.mp4');
        $work = $this->dir->file('work/images/'.$task->id()->value);
        mkdir($work, 0o775, true);

        // A symlink to a directory, which rmdir() refuses whoever is asking:
        // the same shape as the read-only volume and the lost permission this
        // guards against, and one a test can produce as any user.
        $elsewhere = $this->dir->file('work/elsewhere');
        mkdir($elsewhere, 0o775, true);
        symlink($elsewhere, $work.'/link');

        $logger = new RecordingLogger();
        $report = $this->prune($logger)->run($this->cutoff());

        self::assertSame([], $report->taskIds, 'nothing is reported as fully pruned');
        self::assertNull($task->prunedAt(), 'so the next run comes back to it');
        self::assertDirectoryExists($work);
        self::assertStringContainsString('could not delete every file', $logger->everythingLogged());
    }

    public function testATaskInsideTheWindowIsLeftAlone(): void
    {
        $task = $this->settledTask('2026-02-25T00:00:00+00:00');
        $final = $this->videoFile('final_'.$task->id()->value.'.mp4');

        $report = $this->prune()->run($this->cutoff());

        self::assertSame([], $report->taskIds);
        self::assertFileExists($final);
        self::assertNull($task->prunedAt());
    }

    /** Deleting the files of a task being rendered would break the run. */
    public function testATaskThatIsStillRunningIsNeverPruned(): void
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString('2026-01-01T00:00:00+00:00'));
        $task->markProcessing(DateTimeValue::fromString('2026-01-01T00:00:00+00:00'));
        $this->tasks->save($task);

        self::assertSame([], $this->prune()->run($this->cutoff())->taskIds);
    }

    public function testAFailedOrCanceledTaskIsPrunedToo(): void
    {
        $old = DateTimeValue::fromString('2026-01-01T00:00:00+00:00');

        $failed = VideoTask::create(['images' => []], $old);
        $failed->markProcessing($old);
        $failed->markFailed('no se pudo', $old);
        $this->tasks->save($failed);

        $canceled = VideoTask::create(['images' => []], $old);
        $canceled->cancel($old);
        $this->tasks->save($canceled);

        self::assertCount(2, $this->prune()->run($this->cutoff())->taskIds);
    }

    /** A second run must not report the same task again. */
    public function testATaskIsPrunedOnlyOnce(): void
    {
        $this->settledTask('2026-01-01T00:00:00+00:00');

        self::assertCount(1, $this->prune()->run($this->cutoff())->taskIds);
        self::assertSame([], $this->prune()->run($this->cutoff())->taskIds);
    }

    /** The point of --dry-run: find out before a month of videos is gone. */
    public function testADryRunReportsWhatItWouldDeleteAndDeletesNothing(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $final = $this->videoFile('final_'.$task->id()->value.'.mp4');

        $report = $this->prune()->run($this->cutoff(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame([$task->id()->value], $report->taskIds);
        self::assertSame(1, $report->files);
        self::assertFileExists($final);
        self::assertNull($task->prunedAt());
        self::assertNotNull($task->finalVideoUrl());
    }

    /** One run has a bounded cost whatever the backlog is. */
    public function testTheRunIsCappedAtTheLimit(): void
    {
        $this->settledTask('2026-01-01T00:00:00+00:00');
        $this->settledTask('2026-01-02T00:00:00+00:00');
        $this->settledTask('2026-01-03T00:00:00+00:00');

        self::assertCount(2, $this->prune()->run($this->cutoff(), limit: 2)->taskIds);
    }

    /** Oldest first, so a backlog drains in the order it built up. */
    public function testTheOldestTasksGoFirst(): void
    {
        $second = $this->settledTask('2026-01-02T00:00:00+00:00');
        $first = $this->settledTask('2026-01-01T00:00:00+00:00');

        self::assertSame(
            [$first->id()->value],
            $this->prune()->run($this->cutoff(), limit: 1)->taskIds,
        );
    }

    /** A file that is already gone is not an error, and is not counted twice. */
    public function testAMissingFileIsNotCounted(): void
    {
        $this->settledTask('2026-01-01T00:00:00+00:00');

        $report = $this->prune()->run($this->cutoff());

        self::assertSame(0, $report->files);
        self::assertSame(0, $report->bytes);
    }

    public function testTheBytesFreedAreAddedUp(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $this->videoFile('final_'.$task->id()->value.'.mp4', str_repeat('x', 1500));

        self::assertSame(1500, $this->prune()->run($this->cutoff())->bytes);
    }

    public function testARunThatFoundSomethingIsLogged(): void
    {
        $logger = new RecordingLogger();
        $this->settledTask('2026-01-01T00:00:00+00:00');

        $this->prune($logger)->run($this->cutoff());

        self::assertNotSame([], $logger->records);
    }

    public function testAnEmptyRunSaysNothing(): void
    {
        $logger = new RecordingLogger();

        $this->prune($logger)->run($this->cutoff());

        self::assertSame([], $logger->records);
    }

    /**
     * The listing and the deletion are not one instant. A retry landing between
     * them turns these files into the input of a run already under way: the
     * completed parts the new attempt reuses, and the output it is writing. The
     * task is read again under its row lock, and one that has moved is left
     * alone - files, prunedAt and all.
     */
    public function testATaskRetriedBetweenTheListingAndTheLockIsLeftAlone(): void
    {
        $task = $this->failedTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $final = $this->videoFile('final_'.$task->id()->value.'.mp4');
        $clip = $this->videoFile('partial_'.$part->id()->value.'.mp4');

        $this->tasks->beforeLockedRead = function () use ($task): void {
            $task->retry($this->clock->now());
        };

        $report = $this->prune()->run($this->cutoff());

        self::assertFileExists($final, 'the run in progress still needs its output');
        self::assertFileExists($clip, 'and the part it was about to reuse');
        self::assertSame([], $report->taskIds);
        self::assertSame(0, $report->files);
        self::assertNull($task->prunedAt());
    }

    /**
     * And one that got retried *and finished again* in that gap, which the
     * status alone cannot catch.
     *
     * "Settled and unpruned" is only half of the listing's condition; the other
     * half is the cutoff, and it was not repeated under the lock. A task that
     * came back and completed satisfies the half that was - so the newest video
     * in the system, minutes old, was deleted as a leftover, and prunedAt was
     * written over the task that had just produced it, taking it out of every
     * run after this one.
     */
    public function testATaskThatFinishedAgainBetweenTheListingAndTheLockIsLeftAlone(): void
    {
        $task = $this->failedTask('2026-01-01T00:00:00+00:00');
        $part = $this->partOf($task);
        $final = $this->videoFile('final_'.$task->id()->value.'.mp4');
        $clip = $this->videoFile('partial_'.$part->id()->value.'.mp4');

        $this->tasks->beforeLockedRead = function () use ($task): void {
            $now = $this->clock->now();
            $task->retry($now);
            $task->markProcessing($now);
            $task->markCompleted('/videos/final_'.$task->id()->value.'.mp4', $now);
        };

        $report = $this->prune()->run($this->cutoff());

        self::assertFileExists($final, 'the video the new run has just produced');
        self::assertFileExists($clip, 'and the clip it was composed from');
        self::assertSame([], $report->taskIds);
        self::assertSame(0, $report->files);
        self::assertNull($task->prunedAt(), 'nor is it excluded from the runs to come');
    }

    /**
     * The row lock and the deletion have to be the same transaction. Read the
     * task in one and unlink in another and the lock is released before the
     * first file goes, which is every bit as open as not locking at all.
     */
    public function testTheTaskIsReadAndItsFilesDeletedInsideOneTransaction(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $this->videoFile('final_'.$task->id()->value.'.mp4');
        $transaction = new SpyTransaction();
        $inside = false;

        $this->tasks->beforeLockedRead = static function () use ($transaction, &$inside): void {
            $inside = $transaction->running;
        };

        $this->prune(transaction: $transaction)->run($this->cutoff());

        self::assertTrue($inside, 'the locked read happens inside the transaction');
        self::assertNotNull($task->prunedAt());
    }

    /**
     * Two runs of the job overlapping: the second finds a task the first has
     * already dealt with, and must not report it a second time.
     */
    public function testATaskPrunedBetweenTheListingAndTheLockIsNotCountedTwice(): void
    {
        $task = $this->settledTask('2026-01-01T00:00:00+00:00');
        $this->videoFile('final_'.$task->id()->value.'.mp4');

        $this->tasks->beforeLockedRead = function () use ($task): void {
            $task->markPruned($this->clock->now());
        };

        self::assertSame([], $this->prune()->run($this->cutoff())->taskIds);
    }

    /**
     * A worker that is killed mid-render cannot unlink what ffmpeg had already
     * written, and the staging directory is not served, not read and never
     * looked at again: whatever lands there stays until this removes it.
     */
    public function testStaleFilesLeftInStagingAreRemoved(): void
    {
        $stale = $this->stagedFile('final_dead-worker.mp4', '2026-01-01T00:00:00+00:00');

        $report = $this->prune()->run($this->cutoff());

        self::assertFileDoesNotExist($stale);
        self::assertSame(1, $report->files);
        self::assertSame(\strlen('half a video'), $report->bytes);
    }

    /** A render under way writes into staging too, and it is not debris. */
    public function testAFileBeingWrittenRightNowIsLeftInStaging(): void
    {
        $fresh = $this->stagedFile('final_in-flight.mp4', '2026-02-28T23:59:00+00:00');

        $report = $this->prune()->run($this->cutoff());

        self::assertFileExists($fresh);
        self::assertSame(0, $report->files);
    }

    /**
     * A short window does not reach into a render in progress.
     *
     * "Older than the retention cutoff" was the whole test, and the cutoff is
     * an option: `--older-than=1m` unlinked the pathname of a file ffmpeg was
     * still writing to - it stalls, or simply goes more than a minute between
     * writes - and ffmpeg carried on filling an inode with no name, after which
     * the handler found no output and did the work again. The lease is the
     * longest a live render goes without touching its file, and nothing inside
     * one is anybody's leftover.
     */
    public function testAShortWindowStillLeavesARenderInProgressAlone(): void
    {
        // Four minutes old, under the five-minute lease this suite configures.
        $inFlight = $this->stagedFile('g1_partial_slow.mp4', '2026-02-28T23:56:00+00:00');
        // And an hour old, which no run can still be inside.
        $dead = $this->stagedFile('g1_partial_dead.mp4', '2026-02-28T23:00:00+00:00');

        $report = $this->prune()->run(DateTimeValue::fromString('2026-03-01T00:00:00+00:00')->minusSeconds(60));

        self::assertFileExists($inFlight, 'ffmpeg may still be writing to it');
        self::assertFileDoesNotExist($dead);
        self::assertSame(1, $report->files);
    }

    /** A dry run says what would go without touching any of it. */
    public function testADryRunLeavesStagingAlone(): void
    {
        $stale = $this->stagedFile('final_dead-worker.mp4', '2026-01-01T00:00:00+00:00');

        $report = $this->prune()->run($this->cutoff(), dryRun: true);

        self::assertFileExists($stale);
        self::assertSame(1, $report->files);
        self::assertTrue($report->dryRun);
    }

    /** Two months back: everything older than that is out of the window. */
    private function cutoff(): DateTimeValue
    {
        return $this->clock->now()->minusSeconds(30 * 86400);
    }

    private function prune(?RecordingLogger $logger = null, ?SpyTransaction $transaction = null): PruneVideos
    {
        return new PruneVideos(
            $this->tasks,
            $this->partials,
            $this->clock,
            $transaction ?? new SpyTransaction(),
            $this->dir->file('videos'),
            $this->dir->file('work'),
            // A lease of five minutes: the floor under the staging cutoff, so a
            // file younger than one ffmpeg run is never anybody's leftover.
            leaseSeconds: 300,
            animateTimeoutSeconds: 1,
            composeTimeoutSeconds: 1,
            logger: $logger ?? new NullLogger(),
        );
    }

    private function settledTask(string $finishedAt): VideoTask
    {
        $at = DateTimeValue::fromString($finishedAt);
        $task = VideoTask::create(['images' => []], $at);
        $task->markProcessing($at);
        $task->markCompleted('/videos/final_'.$task->id()->value.'.mp4', $at);
        $this->tasks->save($task);

        return $task;
    }

    /** A task whose run ended in failure: settled, so the job may take it. */
    private function failedTask(string $finishedAt): VideoTask
    {
        $at = DateTimeValue::fromString($finishedAt);
        $task = VideoTask::create(['images' => []], $at);
        $task->markProcessing($at);
        $task->markFailed('ffmpeg se rindió', $at);
        $this->tasks->save($task);

        return $task;
    }

    private function partOf(VideoTask $task): PartialVideo
    {
        $at = $task->updatedAt();
        $part = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $at);
        $part->markCompleted('/videos/partial_'.$part->id()->value.'.mp4', $at);
        $this->partials->save($part);

        return $part;
    }

    /** A file in the worker's staging directory, last written at $modifiedAt. */
    private function stagedFile(string $name, string $modifiedAt): string
    {
        $directory = $this->dir->file('videos').'/.staging';

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $file = $directory.'/'.$name;
        file_put_contents($file, 'half a video');
        touch($file, DateTimeValue::fromString($modifiedAt)->toDateTimeImmutable()->getTimestamp());

        return $file;
    }

    private function videoFile(string $name, string $contents = 'video'): string
    {
        $directory = $this->dir->file('videos');

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $file = $directory.'/'.$name;
        file_put_contents($file, $contents);

        return $file;
    }
}
