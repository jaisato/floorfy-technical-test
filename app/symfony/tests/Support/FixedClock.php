<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\DateTimeValue;

final class FixedClock implements Clock
{
    private DateTimeValue $now;

    public function __construct(string $iso8601 = '2026-01-02T03:04:05+00:00')
    {
        $this->now = DateTimeValue::fromString($iso8601);
    }

    public function now(): DateTimeValue
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->minusSeconds(-$seconds);
    }
}
