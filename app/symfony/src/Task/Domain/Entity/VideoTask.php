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
    ): self {
        return new self($id, $payload, $status, $finalVideoUrl, $errorMessage, $createdAt, $updatedAt, $callbackUrl, $renderOptions, $prunedAt);
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
     */
    public function markProcessing(DateTimeValue $now): void
    {
        $this->transitionTo(
            VideoTaskStatus::PROCESSING,
            [VideoTaskStatus::PENDING, VideoTaskStatus::PROCESSING, VideoTaskStatus::FAILED],
            $now,
        );
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
