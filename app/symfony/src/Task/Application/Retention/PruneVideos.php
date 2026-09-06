<?php

declare(strict_types=1);

namespace App\Task\Application\Retention;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\DateTimeValue;
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
 * Only settled tasks are touched. Deleting the files of one that is being
 * rendered would break the run in progress, and a run is bounded by a limit so
 * a backlog is worked through over several runs rather than in one long one.
 */
final readonly class PruneVideos
{
    public const int DEFAULT_LIMIT = 500;

    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
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

        foreach ($this->tasks->listPrunable($before, $limit) as $task) {
            $parts = $this->partials->listByTaskId($task->id());

            foreach ($this->filesOf($task, $parts) as $file) {
                $size = @filesize($file);

                if (false === $size) {
                    continue;
                }

                ++$files;
                $bytes += $size;

                if (!$dryRun) {
                    @unlink($file);
                }
            }

            if (!$dryRun) {
                self::removeDirectory($this->workDir.'/images/'.$task->id()->value);
                $this->markPruned($task, $parts);
            }

            $pruned[] = $task->id()->value;
        }

        if ([] !== $pruned) {
            $this->logger->info($dryRun ? 'Retention run (dry run)' : 'Retention run', [
                'tasks' => \count($pruned),
                'files' => $files,
                'bytes' => $bytes,
            ]);
        }

        return new PruneReport($pruned, $files, $bytes, $dryRun);
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
