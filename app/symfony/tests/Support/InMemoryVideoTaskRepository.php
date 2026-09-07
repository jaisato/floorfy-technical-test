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

    /** @var array<string, DateTimeValue> when the sweep last published one */
    public array $callbacksAttempted = [];

    /** @var array<string, DateTimeValue> when each task's callback was given up on */
    public array $callbacksAbandoned = [];

    /**
     * Which attempt each task is on, exactly as the column does: bumped by
     * every write that starts a run or ends one, and never by a renewal.
     *
     * It is kept here rather than on the stored aggregate because the writes
     * below mutate that shared instance in place, which is what lets a handler
     * see its own transition. The listings hand out copies carrying the
     * current number, which is where a caller reads it.
     *
     * @var array<string, int>
     */
    private array $generations = [];

    /**
     * Run just before getForUpdate() hands a row back, so a test can change the
     * task in the moment the caller believes it is holding it still.
     *
     * @var (callable(UuidValue): void)|null
     */
    public $beforeLockedRead;

    /**
     * Run just before markFailedIfStillRunning() decides, so a test can cancel
     * the task in the window the conditional UPDATE exists to close.
     *
     * @var (callable(UuidValue): void)|null
     */
    public $beforeMarkFailed;

    /**
     * Run just before markCallbackNotified() decides, so a test can retry the
     * task in the window a delivery in flight occupies.
     *
     * @var (callable(UuidValue): void)|null
     */
    public $beforeMarkNotified;

    /**
     * Run just before markCallbackAbandoned() decides, so a test can retry the
     * task in the window a delivery being refused occupies.
     *
     * @var (callable(UuidValue): void)|null
     */
    public $beforeMarkAbandoned;

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

    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): ?int
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task) {
            return null;
        }

        $stale = $task->updatedAt()->toDateTimeImmutable() <= $staleBefore->toDateTimeImmutable();

        $claimable = match ($task->status()) {
            VideoTaskStatus::PENDING, VideoTaskStatus::FAILED => true,
            VideoTaskStatus::PROCESSING => $stale,
            VideoTaskStatus::COMPLETED, VideoTaskStatus::CANCELED => false,
        };

        if (!$claimable) {
            return null;
        }

        $task->markProcessing($now);

        return $this->bump($id);
    }

    public function release(UuidValue $id, int $generation, DateTimeValue $now): ?int
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status() || $this->generation($id) !== $generation) {
            return null;
        }

        $task->markPending($now);

        return $this->bump($id);
    }

    /** Mirrors the conditional write: nothing happens over a settled row. */
    public function markFailedIfStillRunning(UuidValue $id, string $errorMessage, ?int $generation, DateTimeValue $now): ?int
    {
        if (null !== $this->beforeMarkFailed) {
            ($this->beforeMarkFailed)($id);
        }

        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || !\in_array($task->status(), [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING], true)) {
            return null;
        }

        // The attempt this failure belongs to, when the caller can name one.
        if (null !== $generation && $this->generation($id) !== $generation) {
            return null;
        }

        $task->markFailed($errorMessage, $now);

        return $this->bump($id);
    }

    /**
     * Mirrors the conditional write: nothing happens over a completed or failed
     * row. In memory the row and the aggregate are one instance, so a task the
     * handler has just canceled through the domain object reads as canceled
     * here already - and there is no second process that could have finished it
     * in between, which is what the real condition guards against.
     */
    public function cancel(UuidValue $id, DateTimeValue $now): ?int
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task) {
            return null;
        }

        if ($task->isCanceled()) {
            // The handler cancelled the shared instance a moment ago, which is
            // the write this stands for; the number still has to move on, once.
            return $this->generations[$id->value] ?? $this->bump($id);
        }

        if (!\in_array($task->status(), [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING], true)) {
            return null;
        }

        $task->cancel($now);

        return $this->bump($id);
    }

    /**
     * Mirrors the conditional renewal: the deadline only moves for a task this
     * attempt still holds, so a cancellation - or a takeover, which in memory
     * shows as the row no longer being "processing" - reports the claim lost.
     */
    public function renewLease(UuidValue $id, int $generation, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status() || $this->generation($id) !== $generation) {
            return false;
        }

        ++$this->leaseRenewals;
        // Only the deadline moves, and processing -> processing is a legal
        // transition, so the shared instance can carry it: unlike the two
        // writes below there is nothing here the caller's copy must not see.
        $task->markProcessing($now);

        return true;
    }

    public function complete(UuidValue $id, string $finalVideoUrl, int $generation, DateTimeValue $now): ?int
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task || VideoTaskStatus::PROCESSING !== $task->status() || $this->generation($id) !== $generation) {
            return null;
        }

        $this->tasks[$id->value] = self::rowWith($task, VideoTaskStatus::COMPLETED, $finalVideoUrl, $now);

        return $this->bump($id);
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

    public function markCallbackNotified(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool
    {
        if (null !== $this->beforeMarkNotified) {
            ($this->beforeMarkNotified)($id);
        }

        $task = $this->tasks[$id->value] ?? null;

        // Both halves of the condition the database applies: the status the
        // notification announced, and the run that announced it - two runs can
        // end the same way, and only the generation tells them apart.
        if (null === $task
            || $task->status()->value !== $event
            || $this->generation($id) !== $generation
        ) {
            return false;
        }

        $this->callbacksNotified[$id->value] = $now;

        return true;
    }

    public function markCallbackAbandoned(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool
    {
        if (null !== $this->beforeMarkAbandoned) {
            ($this->beforeMarkAbandoned)($id);
        }

        $task = $this->tasks[$id->value] ?? null;

        // The same fence the database applies to the delivered mark: this
        // verdict belongs to the run that reached it.
        if (null === $task
            || $task->status()->value !== $event
            || $this->generation($id) !== $generation
        ) {
            return false;
        }

        $this->callbacksAbandoned[$id->value] = $now;

        return true;
    }

    public function clearCallbackNotification(UuidValue $id): void
    {
        unset(
            $this->callbacksNotified[$id->value],
            $this->callbacksAttempted[$id->value],
            $this->callbacksAbandoned[$id->value],
        );
    }

    public function claimRepublication(UuidValue $id, DateTimeValue $before, DateTimeValue $now): bool
    {
        $task = $this->tasks[$id->value] ?? null;

        if (null === $task
            || VideoTaskStatus::PENDING !== $task->status()
            || $task->updatedAt()->toDateTimeImmutable() >= $before->toDateTimeImmutable()
        ) {
            return false;
        }

        $this->tasks[$id->value] = self::rowWith($task, $task->status(), $task->finalVideoUrl(), $now);

        return true;
    }

    public function claimCallbackNotification(UuidValue $id, int $generation, DateTimeValue $before, DateTimeValue $now): bool
    {
        if (isset($this->callbacksNotified[$id->value]) || isset($this->callbacksAbandoned[$id->value])) {
            return false;
        }

        if ($this->generation($id) !== $generation) {
            return false;
        }

        if ($this->attemptedSince($id, $before)) {
            return false;
        }

        $this->callbacksAttempted[$id->value] = $now;

        return true;
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
                && !isset($this->callbacksAbandoned[$task->id()->value])
                && !$this->attemptedSince($task->id(), $before)
                && \in_array($task->status(), VideoTaskStatus::settled(), true),
            $before,
            $limit,
        );
    }

    /** Whether the sweep already published this notification within the window. */
    private function attemptedSince(UuidValue $id, DateTimeValue $before): bool
    {
        $attempted = $this->callbacksAttempted[$id->value] ?? null;

        return null !== $attempted
            && $attempted->toDateTimeImmutable() >= $before->toDateTimeImmutable();
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

        return array_map($this->withGeneration(...), \array_slice($found, 0, $limit));
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

    /** The attempt the task is on; every row starts on the first one. */
    private function generation(UuidValue $id): int
    {
        return $this->generations[$id->value] ?? VideoTask::FIRST_RUN;
    }

    /** Moves the task on to the next attempt and answers with it. */
    private function bump(UuidValue $id): int
    {
        return $this->generations[$id->value] = $this->generation($id) + 1;
    }

    /**
     * A copy of the task carrying the attempt it is actually on.
     *
     * The writes above mutate the stored instance so a handler can see its own
     * transition, which leaves the generation on that instance behind. The
     * listings are read-only, and the sweep reads the number from what they
     * hand back, so this is where the two are put together.
     */
    private function withGeneration(VideoTask $task): VideoTask
    {
        return VideoTask::rehydrate(
            $task->id(),
            $task->payload(),
            $task->status(),
            $task->finalVideoUrl(),
            $task->errorMessage(),
            $task->createdAt(),
            $task->updatedAt(),
            $task->callbackUrl(),
            $task->renderOptions(),
            $task->prunedAt(),
            $this->generation($task->id()),
        );
    }
}
