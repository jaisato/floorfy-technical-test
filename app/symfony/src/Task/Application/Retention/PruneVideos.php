<?php

declare(strict_types=1);

namespace App\Task\Application\Retention;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\Transaction;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes the videos of tasks nobody has asked about in a while.
 *
 * The files are the only part of this system that grows without bound: a task
 * row is a few hundred bytes, a rendered video is megabytes, and the volume
 * they share fills up. What goes is the media - the final video, the clips and
 * the scratch directory - and what stays is the task, with its URL cleared and
 * prunedAt set: deleting the row would lose the record that the work was done
 * and let an Idempotency-Key that has expired create the same video again.
 *
 * Only settled tasks are touched, and each one under its own row lock: deleting
 * the files of one that is being rendered would break the run in progress. A
 * run is bounded by a limit so a backlog is worked through over several runs
 * rather than in one long one.
 */
final readonly class PruneVideos
{
    public const int DEFAULT_LIMIT = 500;

    /**
     * Where the worker writes a video before publishing it. A render that died
     * - ffmpeg refusing the input, the process killed - leaves its half-written
     * output here, and nothing else ever looks at this directory again.
     */
    private const string STAGING_DIRECTORY = '.staging';

    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private Transaction $transaction,
        #[Autowire(param: 'app.videos_dir')]
        private string $videosDir,
        #[Autowire(param: 'app.work_dir')]
        private string $workDir,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param positive-int $limit
     */
    public function run(DateTimeValue $before, bool $dryRun = false, int $limit = self::DEFAULT_LIMIT): PruneReport
    {
        $pruned = [];
        $files = 0;
        $bytes = 0;

        foreach ($this->tasks->listPrunable($before, $limit) as $listed) {
            // Everything about one task happens under its row lock, taken again
            // here rather than trusted from the listing. In between, a retry can
            // put the task back in the queue - and then these are not leftovers
            // at all, they are the completed parts the new attempt reuses and
            // the files it is writing right now. Deleted anyway, the new run
            // produced a broken video, and prunedAt, written over a task that
            // had just been queued again, excluded that video from every
            // retention run after this one.
            $outcome = $dryRun
                ? $this->measure($listed)
                : $this->transaction->run(fn (): ?PrunedTask => $this->pruneLocked($listed->id()));

            if (null === $outcome) {
                continue;
            }

            if (null !== $outcome->taskId) {
                $pruned[] = $outcome->taskId;
            }

            $files += $outcome->files;
            $bytes += $outcome->bytes;
        }

        $staged = $this->clearStaging($before, $dryRun);
        $files += $staged->files;
        $bytes += $staged->bytes;

        if ([] !== $pruned || $staged->files > 0) {
            $this->logger->info($dryRun ? 'Retention run (dry run)' : 'Retention run', [
                'tasks' => \count($pruned),
                'files' => $files,
                'bytes' => $bytes,
                'staged' => $staged->files,
            ]);
        }

        return new PruneReport($pruned, $files, $bytes, $dryRun);
    }

    /**
     * Deletes one task's videos while holding its row, or reports that there
     * turned out to be nothing to delete.
     */
    private function pruneLocked(UuidValue $id): ?PrunedTask
    {
        $task = $this->tasks->getForUpdate($id);

        if (null === $task || !$task->isPrunable()) {
            // Retried since it was listed, or already pruned by a run that
            // overlapped this one. Either way its files are not ours to delete.
            return null;
        }

        $parts = $this->partials->listByTaskId($id);
        $left = [];
        $files = 0;
        $bytes = 0;

        foreach ($this->filesOf($task, $parts) as $file) {
            $size = @filesize($file);

            if (false === $size) {
                continue;
            }

            if (!@unlink($file)) {
                // Read-only mount, a permission the deployment lost, a file
                // somebody else holds open: whatever it is, the bytes are
                // still on disk and counting them as freed would be a lie.
                $left[] = $file;
                continue;
            }

            ++$files;
            $bytes += $size;
        }

        if ([] !== $left) {
            // Not marked as pruned, on purpose: pruned_at is what stops the
            // job coming back, and a task whose files are still there is
            // exactly the one it must come back to. Left unmarked, the next
            // run tries again; marked, the files would leak for good and
            // every report would say they had gone.
            $this->logger->error('Retention could not delete every file of a task', [
                'task_id' => $id->value,
                'files_left' => \count($left),
                'first' => $left[0],
            ]);

            // The bytes that did go are reported all the same: they are off the
            // volume, and a report that hid them would understate what the run
            // freed and overstate what is left to free.
            return new PrunedTask(null, $files, $bytes);
        }

        self::removeDirectory($this->workDir.'/images/'.$id->value);
        $this->markPruned($task, $parts);

        return new PrunedTask($id->value, $files, $bytes);
    }

    /**
     * What a real run would free for this task.
     *
     * No lock and no writes: a dry run is a question about the state of the
     * disk, and a question must not queue behind a worker rendering a video.
     */
    private function measure(VideoTask $task): PrunedTask
    {
        $files = 0;
        $bytes = 0;

        foreach ($this->filesOf($task, $this->partials->listByTaskId($task->id())) as $file) {
            $size = @filesize($file);

            if (false !== $size) {
                ++$files;
                $bytes += $size;
            }
        }

        return new PrunedTask($task->id()->value, $files, $bytes);
    }

    /**
     * Removes what the worker left in staging.
     *
     * A render that failed unlinks its own output, but a worker that is killed
     * outright - OOM, a container replaced mid-render - cannot, and the file
     * stays under a name nothing will ever publish or read. Only files older
     * than the retention cutoff are touched, which is days: a render in
     * progress is never in that set, so no lock is needed to be sure of it.
     */
    private function clearStaging(DateTimeValue $before, bool $dryRun): PrunedTask
    {
        $cutoff = $before->toDateTimeImmutable()->getTimestamp();
        $files = 0;
        $bytes = 0;

        foreach (glob($this->videosDir.'/'.self::STAGING_DIRECTORY.'/*') ?: [] as $file) {
            $modified = @filemtime($file);
            $size = @filesize($file);

            if (!is_file($file) || false === $modified || false === $size || $modified >= $cutoff) {
                continue;
            }

            if (!$dryRun && !@unlink($file)) {
                $this->logger->error('Retention could not delete a staged file', ['file' => $file]);

                continue;
            }

            ++$files;
            $bytes += $size;
        }

        return new PrunedTask(null, $files, $bytes);
    }

    /**
     * Every file this task owns. Built from the ids rather than from the stored
     * paths, so a clip whose row lost its path - a retry that never finished -
     * is still cleaned up.
     *
     * @param list<PartialVideo> $parts
     *
     * @return list<string>
     */
    private function filesOf(VideoTask $task, array $parts): array
    {
        $files = [$this->videosDir.'/final_'.$task->id()->value.'.mp4'];

        foreach ($parts as $part) {
            $files[] = $this->videosDir.'/partial_'.$part->id()->value.'.mp4';
        }

        return $files;
    }

    /** @param list<PartialVideo> $parts */
    private function markPruned(VideoTask $task, array $parts): void
    {
        $now = $this->clock->now();

        foreach ($parts as $part) {
            $part->markPruned($now);
        }

        if ([] !== $parts) {
            $this->partials->saveAll($parts);
        }

        $task->markPruned($now);
        $this->tasks->save($task);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($directory);
    }
}
