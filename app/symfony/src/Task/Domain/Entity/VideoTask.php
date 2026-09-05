<?php

declare(strict_types=1);

namespace App\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;

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
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function create(array $payload, DateTimeValue $now): self
    {
        return new self(
            UuidValue::new(),
            $payload,
            VideoTaskStatus::PENDING,
            null,
            null,
            $now,
            $now,
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
    ): self {
        return new self($id, $payload, $status, $finalVideoUrl, $errorMessage, $createdAt, $updatedAt);
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
        return \in_array($this->status, [VideoTaskStatus::COMPLETED, VideoTaskStatus::FAILED], true);
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
