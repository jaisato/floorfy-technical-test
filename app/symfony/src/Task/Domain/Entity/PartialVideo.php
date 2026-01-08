<?php
declare(strict_types=1);

namespace App\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;

final class PartialVideo
{
    private function __construct(
        private UuidValue $id,
        private UuidValue $taskId,
        private string $imageUrl,
        private Transition $transition,
        private PartialVideoStatus $status,
        private ?string $videoPath,
        private ?string $errorMessage,
        private DateTimeValue $createdAt,
        private DateTimeValue $updatedAt,
    ) {}

    public static function create(UuidValue $taskId, string $imageUrl, Transition $transition, DateTimeValue $now): self
    {
        return new self(
            UuidValue::new(),
            $taskId,
            $imageUrl,
            $transition,
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
        PartialVideoStatus $status,
        ?string $videoPath,
        ?string $errorMessage,
        DateTimeValue $createdAt,
        DateTimeValue $updatedAt,
    ): self {
        return new self($id, $taskId, $imageUrl, $transition, $status, $videoPath, $errorMessage, $createdAt, $updatedAt);
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

    public function markCompleted(string $videoPath, DateTimeValue $now): void
    {
        $this->status = PartialVideoStatus::COMPLETED;
        $this->videoPath = $videoPath;
        $this->updatedAt = $now;
    }

    public function markFailed(string $errorMessage, DateTimeValue $now): void
    {
        $this->status = PartialVideoStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;
    }
}
