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
        self::assertNotNull($task->finalVideoUrl());
        self::assertStringContainsString('Retention could not delete every file of a task', $logger->everythingLogged());

        rmdir($clip);
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

    /** Two months back: everything older than that is out of the window. */
    private function cutoff(): DateTimeValue
    {
        return $this->clock->now()->minusSeconds(30 * 86400);
    }

    private function prune(?RecordingLogger $logger = null): PruneVideos
    {
        return new PruneVideos(
            $this->tasks,
            $this->partials,
            $this->clock,
            $this->dir->file('videos'),
            $this->dir->file('work'),
            $logger ?? new NullLogger(),
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

    private function partOf(VideoTask $task): PartialVideo
    {
        $at = $task->updatedAt();
        $part = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $at);
        $part->markCompleted('/videos/partial_'.$part->id()->value.'.mp4', $at);
        $this->partials->save($part);

        return $part;
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
