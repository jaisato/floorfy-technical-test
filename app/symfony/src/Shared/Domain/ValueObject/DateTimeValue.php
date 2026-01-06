<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

final readonly class DateTimeValue
{
    private function __construct(private \DateTimeImmutable $value) {}

    public static function now(?\DateTimeZone $tz = null): self
    {
        return new self(new \DateTimeImmutable('now', $tz ?? new \DateTimeZone('UTC')));
    }

    public static function fromString(string $iso8601): self
    {
        return new self(new \DateTimeImmutable($iso8601));
    }

    public static function fromDateTimeImmutable(\DateTimeImmutable $dt): self
    {
        return new self($dt);
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function toIso8601(): string
    {
        return $this->value->format(\DateTimeInterface::ATOM);
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }
}
