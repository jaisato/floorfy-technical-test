<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

final readonly class UuidValue
{
    private function __construct(public string $value)
    {
    }

    public static function new(): self
    {
        return new self(Uuid::v7()->toRfc4122());
    }

    public static function fromString(string $value): self
    {
        Uuid::fromString($value);

        return new self($value);
    }

    /**
     * Null instead of an exception when the text is not a UUID.
     *
     * For a lookup, "this is not a well-formed id" and "no task has this id"
     * are the same answer to the caller. fromString() throws, so a read path
     * that only knows how to report "not found" turned a typo in the URL into
     * an unhandled exception - and a 500.
     */
    public static function tryFromString(string $value): ?self
    {
        if (!Uuid::isValid($value)) {
            return null;
        }

        return new self($value);
    }
}
