<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;

/**
 * Mirrors the conditional claim of the Doctrine adapter: the same row is
 * claimable exactly once until it is released or its lease goes stale.
 */
final class InMemoryVideoTaskRepository implements VideoTaskRepository
{
    /** @var array<string, VideoTask> */
    private array $tasks = [];

    public int $saves = 0;

    public function save(VideoTask $task): void
    {
        ++$this->saves;
        $this->tasks[$task->id()->value] = $task;
    }

    public function get(UuidValue $id): ?VideoTask
    {
        return $this->tasks[$id->value] ?? null;
    }

    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task) {
            return false;
        }

        $stale = $task->updatedAt()->toDateTimeImmutable() <= $staleBefore->toDateTimeImmutable();

        $claimable = match ($task->status()) {
            VideoTaskStatus::PENDING, VideoTaskStatus::FAILED => true,
            VideoTaskStatus::PROCESSING => $stale,
            VideoTaskStatus::COMPLETED => false,
        };

        if (!$claimable) {
            return false;
        }

        $task->markProcessing($now);

        return true;
    }

    public function release(UuidValue $id, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status()) {
            return false;
        }

        $task->markPending($now);

        return true;
    }

    /** @return list<VideoTask> */
    public function all(): array
    {
        return array_values($this->tasks);
    }
}
