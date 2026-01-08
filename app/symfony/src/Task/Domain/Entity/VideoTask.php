<?php
declare(strict_types=1);

namespace App\Task\Domain\Entity;

use App\Task\Domain\Enum\VideoTaskStatus;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;

final class VideoTask
{
    /** @param array<string,mixed> $payload */
    private function __construct(
        private UuidValue $id,
        private array $payload,
        private VideoTaskStatus $status,
        private ?string $finalVideoUrl,
        private ?string $errorMessage,
        private DateTimeValue $createdAt,
        private DateTimeValue $updatedAt,
    ) {}

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

    public function markProcessing(DateTimeValue $now): void
    {
        $this->status = VideoTaskStatus::PROCESSING;
        $this->updatedAt = $now;
    }

    public function markCompleted(string $finalVideoUrl, DateTimeValue $now): void
    {
        $this->status = VideoTaskStatus::COMPLETED;
        $this->finalVideoUrl = $finalVideoUrl;
        $this->updatedAt = $now;
    }

    public function markFailed(string $errorMessage, DateTimeValue $now): void
    {
        $this->status = VideoTaskStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;
    }
}
