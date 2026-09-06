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
            VideoTaskStatus::COMPLETED, VideoTaskStatus::CANCELED => false,
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

    /**
     * Mirrors the conditional write: nothing happens over a completed or failed
     * row. In memory the row and the aggregate are one instance, so a task the
     * handler has just canceled through the domain object reads as canceled
     * here already - and there is no second process that could have finished it
     * in between, which is what the real condition guards against.
     */
    public function cancel(UuidValue $id, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task) {
            return false;
        }

        if ($task->isCanceled()) {
            return true;
        }

        if (!\in_array($task->status(), [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING], true)) {
            return false;
        }

        $task->cancel($now);

        return true;
    }

    public function currentStatus(UuidValue $id): ?VideoTaskStatus
    {
        return ($this->tasks[$id->value] ?? null)?->status();
    }

    public function listPrunable(DateTimeValue $before, int $limit): array
    {
        $prunable = [];

        foreach ($this->tasks as $task) {
            if (null !== $task->prunedAt()
                || !\in_array($task->status(), VideoTaskStatus::settled(), true)
                || $task->updatedAt()->toDateTimeImmutable() >= $before->toDateTimeImmutable()
            ) {
                continue;
            }

            $prunable[] = $task;
        }

        usort(
            $prunable,
            static fn (VideoTask $a, VideoTask $b): int => $a->updatedAt()->toDateTimeImmutable() <=> $b->updatedAt()->toDateTimeImmutable(),
        );

        return \array_slice($prunable, 0, $limit);
    }

    /** @return list<VideoTask> */
    public function all(): array
    {
        return array_values($this->tasks);
    }
}
