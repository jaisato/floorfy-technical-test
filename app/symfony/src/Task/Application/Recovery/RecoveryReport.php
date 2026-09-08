<?php

declare(strict_types=1);

namespace App\Task\Application\Recovery;

/**
 * What one recovery run published again, or would have.
 */
final readonly class RecoveryReport
{
    /**
     * @param list<string> $requeuedTaskIds   tasks left at "pending" whose processing message was published again
     * @param list<string> $renotifiedTaskIds settled tasks whose callback was published again
     * @param list<string> $releasedTaskIds   claims of workers that never came back, handed back to "pending"
     */
    public function __construct(
        public array $requeuedTaskIds,
        public array $renotifiedTaskIds,
        public bool $dryRun,
        public array $releasedTaskIds = [],
    ) {
    }

    public function requeued(): int
    {
        return \count($this->requeuedTaskIds);
    }

    public function renotified(): int
    {
        return \count($this->renotifiedTaskIds);
    }

    public function released(): int
    {
        return \count($this->releasedTaskIds);
    }

    public function isEmpty(): bool
    {
        return [] === $this->requeuedTaskIds && [] === $this->renotifiedTaskIds && [] === $this->releasedTaskIds;
    }
}
