<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\DomainException;

/**
 * An instant, always carried in UTC.
 *
 * Everything this application stores is a timestamp in a DATETIME column, which
 * has no zone: two values that only agree once their offsets are applied are
 * indistinguishable once written. Normalising on the way in is what keeps
 * ordering and comparisons meaningful.
 */
final readonly class DateTimeValue implements \Stringable
{
    private function __construct(private \DateTimeImmutable $value)
    {
    }

    public static function now(): self
    {
        return new self(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * @throws DomainException when the text is not a date this class can carry
     */
    public static function fromString(string $iso8601): self
    {
        try {
            // The zone given here is only a fallback: an input that names its
            // own offset keeps it, and is converted below.
            $parsed = new \DateTimeImmutable($iso8601, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new DomainException(\sprintf('Fecha inválida: "%s".', $iso8601), 0, $e);
        }

        return self::fromDateTimeImmutable($parsed);
    }

    public static function fromDateTimeImmutable(\DateTimeImmutable $dt): self
    {
        return new self($dt->setTimezone(new \DateTimeZone('UTC')));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function toIso8601(): string
    {
        return $this->value->format(\DateTimeInterface::ATOM);
    }

    public function minusSeconds(int $seconds): self
    {
        return new self($this->value->modify(\sprintf('%+d seconds', -$seconds)));
    }

    public function equals(self $other): bool
    {
        return $this->value->getTimestamp() === $other->value->getTimestamp();
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }
}
