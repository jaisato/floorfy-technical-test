<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Transaction\Transaction;

/**
 * Runs the work immediately and remembers that it was inside a transaction
 * while doing so.
 */
final class SpyTransaction implements Transaction
{
    public bool $running = false;
    public int $maxDepth = 0;

    private int $depth = 0;

    public function run(callable $work): mixed
    {
        ++$this->depth;
        $this->maxDepth = max($this->maxDepth, $this->depth);
        $this->running = true;

        try {
            return $work();
        } finally {
            --$this->depth;
            $this->running = $this->depth > 0;
        }
    }
}
