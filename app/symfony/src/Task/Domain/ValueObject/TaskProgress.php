<?php

declare(strict_types=1);

namespace App\Task\Domain\ValueObject;

use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\PartialVideoStatus;

/**
 * How far a task has got, counted over its parts.
 *
 * The status of a task says whether it is done; a client polling a task with
 * twenty images wants to know how many of them are, and which way the rest are
 * heading. The percentage is floored, so it never reads 100 while a part is
 * still outstanding.
 */
final readonly class TaskProgress
{
    private function __construct(
        public int $completed,
        public int $failed,
        public int $pending,
        public int $total,
        public int $percent,
    ) {
    }

    /** @param list<PartialVideo> $parts */
    public static function ofParts(array $parts): self
    {
        $completed = 0;
        $failed = 0;

        foreach ($parts as $part) {
            match ($part->status()) {
                PartialVideoStatus::COMPLETED => ++$completed,
                PartialVideoStatus::FAILED => ++$failed,
                PartialVideoStatus::PENDING => null,
            };
        }

        return self::ofCounts($completed, $failed, \count($parts) - $completed - $failed);
    }

    public static function ofCounts(int $completed, int $failed, int $pending): self
    {
        if ($completed < 0 || $failed < 0 || $pending < 0) {
            throw new \InvalidArgumentException('Progress counts cannot be negative.');
        }

        $total = $completed + $failed + $pending;

        return new self(
            $completed,
            $failed,
            $pending,
            $total,
            0 === $total ? 0 : intdiv($completed * 100, $total),
        );
    }

    /** @return array{completed: int, failed: int, pending: int, total: int, percent: int} */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'pending' => $this->pending,
            'total' => $this->total,
            'percent' => $this->percent,
        ];
    }
}
