<?php

declare(strict_types=1);

namespace App\Task\Application\Retention;

/**
 * What one task's turn in the retention job freed.
 *
 * The per-task work happens inside a transaction, so it cannot add to counters
 * held by the run; it hands back what it did instead.
 *
 * The id is null when the bytes went but the task was not marked as pruned -
 * a file that would not be deleted leaves it for the next run - and the bytes
 * are still counted, because they really are gone.
 */
final readonly class PrunedTask
{
    public function __construct(
        public ?string $taskId,
        public int $files,
        public int $bytes,
    ) {
    }
}
