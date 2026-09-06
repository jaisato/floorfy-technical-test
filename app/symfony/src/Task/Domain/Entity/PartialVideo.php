<?php

declare(strict_types=1);

namespace App\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\ValueObject\RenderOptions;

final class PartialVideo
{
    private function __construct(
        private readonly UuidValue $id,
        private readonly UuidValue $taskId,
        private readonly string $imageUrl,
        private readonly Transition $transition,
        /** Playback order inside the task; assigned when the task is created. */
        private readonly int $position,
        /** Seconds this clip lasts; null means the task's default. */
        private readonly ?float $durationSeconds,
        private PartialVideoStatus $status,
        private ?string $videoPath,
        private ?string $errorMessage,
        private readonly DateTimeValue $createdAt,
        private DateTimeValue $updatedAt,
    ) {
    }

    public static function create(UuidValue $taskId, string $imageUrl, Transition $transition, int $position, DateTimeValue $now, ?float $durationSeconds = null): self
    {
        return new self(
            UuidValue::new(),
            $taskId,
            $imageUrl,
            $transition,
            $position,
            $durationSeconds,
            PartialVideoStatus::PENDING,
            null,
            null,
            $now,
            $now,
        );
    }

    public static function rehydrate(
        UuidValue $id,
        UuidValue $taskId,
        string $imageUrl,
        Transition $transition,
        int $position,
        PartialVideoStatus $status,
        ?string $videoPath,
        ?string $errorMessage,
        DateTimeValue $createdAt,
        DateTimeValue $updatedAt,
        ?float $durationSeconds = null,
    ): self {
        return new self($id, $taskId, $imageUrl, $transition, $position, $durationSeconds, $status, $videoPath, $errorMessage, $createdAt, $updatedAt);
    }

    public function id(): UuidValue
    {
        return $this->id;
    }

    public function taskId(): UuidValue
    {
        return $this->taskId;
    }

    public function imageUrl(): string
    {
        return $this->imageUrl;
    }

    public function transition(): Transition
    {
        return $this->transition;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** Null when this clip runs for whatever the task says. */
    public function durationSeconds(): ?float
    {
        return $this->durationSeconds;
    }

    /** The options this clip is rendered with: the task's, with its own length. */
    public function renderOptions(RenderOptions $taskOptions): RenderOptions
    {
        return null === $this->durationSeconds ? $taskOptions : $taskOptions->withDuration($this->durationSeconds);
    }

    public function status(): PartialVideoStatus
    {
        return $this->status;
    }

    public function videoPath(): ?string
    {
        return $this->videoPath;
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

    public function isCompleted(): bool
    {
        return PartialVideoStatus::COMPLETED === $this->status;
    }

    public function markCompleted(string $videoPath, DateTimeValue $now): void
    {
        $this->status = PartialVideoStatus::COMPLETED;
        $this->videoPath = $videoPath;
        // A part that succeeds on a later attempt must not keep advertising why
        // the previous one failed.
        $this->errorMessage = null;
        $this->updatedAt = $now;
    }

    public function markFailed(string $errorMessage, DateTimeValue $now): void
    {
        $this->status = PartialVideoStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;
    }

    /**
     * Puts a failed part back in the queue so the next attempt retries it.
     *
     * Without this a single transient download error would pin the part at
     * "failed" for good, and the task could never complete however many times
     * the message was redelivered.
     */
    public function markPending(DateTimeValue $now): void
    {
        $this->status = PartialVideoStatus::PENDING;
        $this->videoPath = null;
        $this->updatedAt = $now;
    }
}
