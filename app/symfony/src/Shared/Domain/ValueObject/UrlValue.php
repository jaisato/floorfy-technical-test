<?php
declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\DomainException;

final readonly class UrlValue
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new DomainException('URL inválida.');
        }
        return new self($value);
    }
}
