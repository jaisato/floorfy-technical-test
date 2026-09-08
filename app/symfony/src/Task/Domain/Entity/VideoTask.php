<?php

declare(strict_types=1);

namespace App\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\ValueObject\RenderOptions;

final class VideoTask
{
    /** The generation a task is created with, and the column's default. */
    public const int FIRST_RUN = 1;

    /** @param array<string,mixed> $payload */
    private function __construct(
        private readonly UuidValue $id,
        private readonly array $payload,
        private VideoTaskStatus $status,
        private ?string $finalVideoUrl,
        private ?string $errorMessage,
        private readonly DateTimeValue $createdAt,
        private DateTimeValue $updatedAt,
        /** Where to POST the outcome, if the client asked to be told. */
        private readonly ?string $callbackUrl,
        /**
         * How the task is rendered. Null on rows written before options
         * existed; the worker falls back to the deployment's defaults.
         */
        private readonly ?RenderOptions $renderOptions,
        /** When the retention job deleted this task's videos, if it has. */
        private ?DateTimeValue $prunedAt,
        /**
         * Which attempt of this task the row is on.
         *
         * Read-only here: it is moved on by the repository's conditional
         * writes, which are the only things that can decide a race. What it is
         * for is naming one attempt - a status is reusable, and so is the
         * second that `updated_at` records - so that a worker cannot renew or
         * finish a claim that has since been taken from it, and a callback
         * cannot be recorded as delivered for a run that is not the one it
         * announced.
         */
        private readonly int $runGeneration,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function create(array $payload, DateTimeValue $now, ?string $callbackUrl = null, ?RenderOptions $renderOptions = null): self
    {
        return new self(
            UuidValue::new(),
            $payload,
            VideoTaskStatus::PENDING,
            null,
            null,
            $now,
            $now,
            $callbackUrl,
            $renderOptions,
            null,
            self::FIRST_RUN,
        );
    }

    /** @param array<string,mixed> $payload */
    public static function rehydrate(
        UuidValue $id,
        array $payload,
        VideoTaskStatus $status,
        ?string $finalVideoUrl,
        ?string $errorMessage,
        DateTimeValue $createdAt,
        DateTimeValue $updatedAt,
        ?string $callbackUrl = null,
        ?RenderOptions $renderOptions = null,
        ?DateTimeValue $prunedAt = null,
        int $runGeneration = self::FIRST_RUN,
    ): self {
        return new self($id, $payload, $status, $finalVideoUrl, $errorMessage, $createdAt, $updatedAt, $callbackUrl, $renderOptions, $prunedAt, $runGeneration);
    }

    /** Which attempt of this task the row was on when it was read. */
    public function runGeneration(): int
    {
        return $this->runGeneration;
    }

    public function callbackUrl(): ?string
    {
        return $this->callbackUrl;
    }

    public function renderOptions(): ?RenderOptions
    {
        return $this->renderOptions;
    }

    public function prunedAt(): ?DateTimeValue
    {
        return $this->prunedAt;
    }

    /**
     * The videos are gone; the task itself stays.
     *
     * Deleting the row instead would lose the record that the work was done
     * and let the same request be replayed as new. Clearing the URL is what
     * stops a client following a link to a file that is no longer there, and
     * prunedAt is what says why.
     */
    public function markPruned(DateTimeValue $now): void
    {
        $this->finalVideoUrl = null;
        $this->prunedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Drops the pointer to a final video that is no longer on disk, without
     * saying the task has been pruned.
     *
     * For the retention run that deleted some of a task's files and could not
     * delete the rest. It deliberately does not mark the task: pruned_at is
     * what takes it out of the sweep, and a task with files still on the volume
     * is the one the sweep must come back to. But the file this URL named is
     * gone all the same, and left in place the API went on handing clients a
     * link to a video the run had just deleted.
     *
     * updatedAt is left alone for the same reason as pruned_at: the sweep lists
     * settled tasks untouched since a cutoff, and moving it would hold this one
     * back for a whole retention window - the files it could not delete with
     * it. Nothing about the task's own history changed here; a dead pointer was
     * taken down.
     */
    public function forgetFinalVideo(): void
    {
        $this->finalVideoUrl = null;
    }

    public function id(): UuidValue
    {
        return $this->id;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): VideoTaskStatus
    {
        return $this->status;
    }

    public function finalVideoUrl(): ?string
    {
        return $this->finalVideoUrl;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function createdAt(): DateTimeValue
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeValue
    {
        return $this->updatedAt;
    }

    public function isSettled(): bool
    {
        return \in_array($this->status, [VideoTaskStatus::COMPLETED, VideoTaskStatus::FAILED, VideoTaskStatus::CANCELED], true);
    }

    public function isCanceled(): bool
    {
        return VideoTaskStatus::CANCELED === $this->status;
    }

    /**
     * FAILED is an accepted starting point: the dead-letter queue exists so an
     * operator can requeue a task once the cause is fixed, and a status nothing
     * can leave would make `messenger:failed:retry` a no-op.
     *
     * Starting a run clears what the previous one left, the same three fields
     * retry() clears - that endpoint is one way in here, `messenger:failed:retry`
     * is the other, and only the first went through retry(). A task replayed
     * from the failure transport after the retention sweep had reclaimed its
     * files kept prunedAt: it rendered a new video, told clients its files had
     * been reclaimed, and - because prunedAt is exactly what excludes a row
     * from the sweep - those new files were the one set nothing would ever
     * clean up again.
     */
    public function markProcessing(DateTimeValue $now): void
    {
        $this->transitionTo(
            VideoTaskStatus::PROCESSING,
            [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING, VideoTaskStatus::FAILED],
            $now,
        );

        $this->errorMessage = null;
        $this->finalVideoUrl = null;
        $this->prunedAt = null;
    }

    /**
     * Hands the task back to the queue between two attempts.
     *
     * A task left at "processing" after a failed attempt cannot be claimed
     * again, which is what made the configured retries unreachable.
     */
    public function markPending(DateTimeValue $now): void
    {
        $this->transitionTo(VideoTaskStatus::PENDING, [VideoTaskStatus::PROCESSING], $now);
    }

    public function markCompleted(string $finalVideoUrl, DateTimeValue $now): void
    {
        $this->transitionTo(VideoTaskStatus::COMPLETED, [VideoTaskStatus::PROCESSING], $now);

        $this->finalVideoUrl = $finalVideoUrl;
        $this->errorMessage = null;
    }

    /**
     * Terminal failure: the message has exhausted its retries.
     */
    public function markFailed(string $errorMessage, DateTimeValue $now): void
    {
        $this->transitionTo(
            VideoTaskStatus::FAILED,
            [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING, VideoTaskStatus::FAILED],
            $now,
        );

        $this->errorMessage = $errorMessage;
    }

    /**
     * Stops a task that has not finished. A pending one will never be picked
     * up (the claim refuses a canceled task); a processing one is noticed by
     * its worker at the next part boundary, which then stops.
     *
     * A finished task cannot be canceled: its video exists, or its failure has
     * been reported, and "canceled" would misdescribe either.
     */
    public function cancel(DateTimeValue $now): void
    {
        $this->transitionTo(
            VideoTaskStatus::CANCELED,
            [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING],
            $now,
        );
    }

    /**
     * Queues a task that ended in failure, or was canceled, for another run.
     *
     * The reason for the earlier failure is cleared: what the task shows from
     * now on is the outcome of the new attempt. Whatever parts were completed
     * are kept by the caller; this only moves the task itself.
     *
     * prunedAt goes with it. It records that this task's videos were deleted,
     * and a task about to render new ones is not in that state: left set, the
     * task told clients its files had been reclaimed while it was producing
     * fresh ones, and - because prunedAt is exactly what excludes a row from
     * the retention sweep - those new files were the one set nothing would ever
     * clean up again.
     */
    public function retry(DateTimeValue $now): void
    {
        $this->transitionTo(
            VideoTaskStatus::PENDING,
            [VideoTaskStatus::FAILED, VideoTaskStatus::CANCELED],
            $now,
        );

        $this->errorMessage = null;
        $this->finalVideoUrl = null;
        $this->prunedAt = null;
    }

    /**
     * Whether the retention job may delete this task's videos right now.
     *
     * Asked again under the row lock, because the answer can change between
     * listing a task and getting to it: a retry moves it out of a settled
     * status, and its files are then the input of a run in progress.
     */
    public function isPrunable(): bool
    {
        return null === $this->prunedAt && \in_array($this->status, VideoTaskStatus::settled(), true);
    }

    /** @param non-empty-list<VideoTaskStatus> $allowedFrom */
    private function transitionTo(VideoTaskStatus $target, array $allowedFrom, DateTimeValue $now): void
    {
        if (!\in_array($this->status, $allowedFrom, true)) {
            throw InvalidTaskTransition::between($this->status, $target);
        }

        $this->status = $target;
        $this->updatedAt = $now;
    }
}
