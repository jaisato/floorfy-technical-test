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

    public int $leaseRenewals = 0;

    /** @var array<string, DateTimeValue> when each task's callback was delivered */
    public array $callbacksNotified = [];

    /**
     * Run just before getForUpdate() hands a row back, so a test can change the
     * task in the moment the caller believes it is holding it still.
     *
     * @var (callable(UuidValue): void)|null
     */
    public $beforeLockedRead;

    public function save(VideoTask $task): void
    {
        ++$this->saves;
        $this->tasks[$task->id()->value] = $task;
    }

    public function get(UuidValue $id): ?VideoTask
    {
        return $this->tasks[$id->value] ?? null;
    }

    /**
     * There is no second process here to lock anything out, so the row is read
     * like any other - through the hook above, which is how a test puts a
     * concurrent change in the window between listing a task and locking it.
     */
    public function getForUpdate(UuidValue $id): ?VideoTask
    {
        if (null !== $this->beforeLockedRead) {
            ($this->beforeLockedRead)($id);
        }

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

    /**
     * Mirrors the conditional renewal: the deadline only moves for a task this
     * attempt still holds, so a cancellation - or a takeover, which in memory
     * shows as the row no longer being "processing" - reports the claim lost.
     */
    public function renewLease(UuidValue $id, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status()) {
            return false;
        }

        ++$this->leaseRenewals;
        // Only the deadline moves, and processing -> processing is a legal
        // transition, so the shared instance can carry it: unlike the two
        // writes below there is nothing here the caller's copy must not see.
        $task->markProcessing($now);

        return true;
    }

    public function complete(UuidValue $id, string $finalVideoUrl, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status()) {
            return false;
        }

        $this->tasks[$id->value] = self::rowWith($task, VideoTaskStatus::COMPLETED, $finalVideoUrl, $now);

        return true;
    }

    /**
     * A new instance standing for the row after the conditional UPDATE that
     * completes a task.
     *
     * That write goes straight to SQL in the real adapter, so the aggregate the
     * caller is holding learns nothing from it - and the caller goes on to
     * update its own copy. Mutating the shared instance here instead would make
     * the handler's own `markCompleted()` a second transition out of a state it
     * had already reached.
     */
    private static function rowWith(VideoTask $task, VideoTaskStatus $status, ?string $finalVideoUrl, DateTimeValue $updatedAt): VideoTask
    {
        return VideoTask::rehydrate(
            $task->id(),
            $task->payload(),
            $status,
            $finalVideoUrl,
            VideoTaskStatus::COMPLETED === $status ? null : $task->errorMessage(),
            $task->createdAt(),
            $updatedAt,
            $task->callbackUrl(),
            $task->renderOptions(),
            $task->prunedAt(),
        );
    }

    public function markCallbackNotified(UuidValue $id, DateTimeValue $now): void
    {
        $this->callbacksNotified[$id->value] = $now;
    }

    public function clearCallbackNotification(UuidValue $id): void
    {
        unset($this->callbacksNotified[$id->value]);
    }

    public function listUnclaimedSince(DateTimeValue $before, int $limit): array
    {
        return $this->oldestFirst(
            static fn (VideoTask $task): bool => VideoTaskStatus::PENDING === $task->status(),
            $before,
            $limit,
        );
    }

    public function listAwaitingCallback(DateTimeValue $before, int $limit): array
    {
        return $this->oldestFirst(
            fn (VideoTask $task): bool => null !== $task->callbackUrl()
                && !isset($this->callbacksNotified[$task->id()->value])
                && \in_array($task->status(), VideoTaskStatus::settled(), true),
            $before,
            $limit,
        );
    }

    /**
     * @param callable(VideoTask): bool $matches
     *
     * @return list<VideoTask>
     */
    private function oldestFirst(callable $matches, DateTimeValue $before, int $limit): array
    {
        $found = [];

        foreach ($this->tasks as $task) {
            if ($matches($task) && $task->updatedAt()->toDateTimeImmutable() < $before->toDateTimeImmutable()) {
                $found[] = $task;
            }
        }

        usort(
            $found,
            static fn (VideoTask $a, VideoTask $b): int => $a->updatedAt()->toDateTimeImmutable() <=> $b->updatedAt()->toDateTimeImmutable(),
        );

        return \array_slice($found, 0, $limit);
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
