<?php

declare(strict_types=1);

namespace App\Task\Application\Retention;

/**
 * What one run of the retention job did, or would have done.
 */
final readonly class PruneReport
{
    /** @param list<string> $taskIds */
    public function __construct(
        public array $taskIds,
        public int $files,
        public int $bytes,
        public bool $dryRun,
    ) {
    }

    public function tasks(): int
    {
        return \count($this->taskIds);
    }
}
