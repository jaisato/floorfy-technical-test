<?php
declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

final readonly class UuidValue
{
    private function __construct(public string $value) {}

    public static function new(): self
    {
        return new self(Uuid::v7()->toRfc4122());
    }

    public static function fromString(string $value): self
    {
        Uuid::fromString($value);

        return new self($value);
    }
}
