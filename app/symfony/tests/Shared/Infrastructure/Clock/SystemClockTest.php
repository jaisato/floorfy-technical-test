<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Clock;

use App\Shared\Infrastructure\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    /**
     * The lease arithmetic compares this against timestamps read back from
     * DATETIME columns, which carry no zone: a clock in the server's local time
     * would make a claim look stale, or fresh, by the size of its offset.
     */
    public function testItReadsTheCurrentInstantInUtc(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = new SystemClock()->now();
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame('UTC', $now->toDateTimeImmutable()->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->toDateTimeImmutable()->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->toDateTimeImmutable()->getTimestamp());
    }
}
