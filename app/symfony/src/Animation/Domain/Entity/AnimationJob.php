<?php
declare(strict_types=1);

namespace App\Animation\Domain\Entity;

use App\Animation\Domain\Enum\AnimationStatus;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;

final class AnimationJob
{
    private function __construct(
        private UuidValue $id,
        private string $prompt,
        private string $imageUrl,
        private ?string $veoOperationId,
        private AnimationStatus $status,
        private ?string $videoUrl,
        private ?string $errorMessage,
        private DateTimeValue $createdAt,
        private DateTimeValue $updatedAt,
    ) {}

    public static function create(string $prompt, string $imageUrl, DateTimeValue $now): self
    {
        return new self(
            UuidValue::new(),
            $prompt,
            $imageUrl,
            null,
            AnimationStatus::REQUESTED,
            null,
            null,
            $now,
            $now
        );
    }

    public static function rehydrate(
        UuidValue $id,
        string $prompt,
        string $imageUrl,
        ?string $veoOperationId,
        AnimationStatus $status,
        ?string $videoUrl,
        ?string $errorMessage,
        DateTimeValue $createdAt,
        DateTimeValue $updatedAt,
    ): self {
        return new self(
            $id,
            $prompt,
            $imageUrl,
            $veoOperationId,
            $status,
            $videoUrl,
            $errorMessage,
            $createdAt,
            $updatedAt
        );
    }

    public function id(): UuidValue { return $this->id; }
    public function prompt(): string { return $this->prompt; }
    public function imageUrl(): string { return $this->imageUrl; }
    public function status(): AnimationStatus { return $this->status; }
    public function veoOperationId(): ?string { return $this->veoOperationId; }
    public function videoUrl(): ?string { return $this->videoUrl; }
    public function errorMessage(): ?string { return $this->errorMessage; }
    public function createdAt(): DateTimeValue { return $this->createdAt; }
    public function updatedAt(): DateTimeValue { return $this->updatedAt; }

    public function markRunning(string $operationId, DateTimeValue $now): void
    {
        $this->veoOperationId = $operationId;
        $this->status = AnimationStatus::RUNNING;
        $this->updatedAt = $now;
    }

    public function markSucceeded(string $videoUrl, DateTimeValue $now): void
    {
        $this->videoUrl = $videoUrl;
        $this->status = AnimationStatus::SUCCEEDED;
        $this->updatedAt = $now;
    }

    public function markFailed(string $error, DateTimeValue $now): void
    {
        $this->errorMessage = $error;
        $this->status = AnimationStatus::FAILED;
        $this->updatedAt = $now;
    }
}
