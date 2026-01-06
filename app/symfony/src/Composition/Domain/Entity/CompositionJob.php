<?php
declare(strict_types=1);

namespace App\Composition\Domain\Entity;

use App\Composition\Domain\Enum\CompositionStatus;
use App\Shared\Domain\ValueObject\UuidValue;

final class CompositionJob
{
    private CompositionStatus $status;
    private ?string $outputUrl = null;
    private ?string $errorMessage = null;

    /** @param list<string> $animationVideoUrls */
    private function __construct(
        private UuidValue $id,
        private array $animationVideoUrls,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->status = CompositionStatus::QUEUED;
    }

    /** @param list<string> $animationVideoUrls */
    public static function create(UuidValue $id, array $animationVideoUrls, \DateTimeImmutable $now): self
    {
        return new self($id, $animationVideoUrls, $now, $now);
    }

    /**
     * Rehidrata el agregado desde persistencia.
     *
     * @param list<string> $animationVideoUrls
     */
    public static function rehydrate(
        UuidValue $id,
        array $animationVideoUrls,
        CompositionStatus $status,
        ?string $outputUrl,
        ?string $errorMessage,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): self {
        $self = new self($id, $animationVideoUrls, $createdAt, $updatedAt);
        $self->status = $status;
        $self->outputUrl = $outputUrl;
        $self->errorMessage = $errorMessage;

        return $self;
    }

    public function markRunning(\DateTimeImmutable $now): void
    {
        $this->status = CompositionStatus::RUNNING;
        $this->updatedAt = $now;
    }

    public function markSucceeded(string $outputUrl, \DateTimeImmutable $now): void
    {
        $this->status = CompositionStatus::SUCCEEDED;
        $this->outputUrl = $outputUrl;
        $this->updatedAt = $now;
    }

    public function markFailed(string $message, \DateTimeImmutable $now): void
    {
        $this->status = CompositionStatus::FAILED;
        $this->errorMessage = $message;
        $this->updatedAt = $now;
    }

    public function id(): UuidValue { return $this->id; }

    /** @return list<string> */
    public function animationVideoUrls(): array { return $this->animationVideoUrls; }

    public function status(): CompositionStatus { return $this->status; }

    public function outputUrl(): ?string { return $this->outputUrl; }

    public function errorMessage(): ?string { return $this->errorMessage; }

    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
