<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\ValueObject\DateTimeValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateTimeValueTest extends TestCase
{
    /**
     * Timestamps land in DATETIME columns, which carry no zone: two values that
     * only agree once their offsets are applied are indistinguishable once
     * written, so ordering and comparisons depend on this normalisation.
     */
    #[DataProvider('sameInstantInDifferentZones')]
    public function testEveryInputIsCarriedInUtc(string $input): void
    {
        $value = DateTimeValue::fromString($input);

        self::assertSame('UTC', $value->toDateTimeImmutable()->getTimezone()->getName());
        self::assertSame('2026-01-02T03:04:05+00:00', $value->toIso8601());
    }

    /** @return iterable<string, array{string}> */
    public static function sameInstantInDifferentZones(): iterable
    {
        yield 'utc' => ['2026-01-02T03:04:05+00:00'];
        yield 'zulu' => ['2026-01-02T03:04:05Z'];
        yield 'madrid' => ['2026-01-02T04:04:05+01:00'];
        yield 'new york' => ['2026-01-01T22:04:05-05:00'];
    }

    /** An input with no zone at all is read as UTC, not as the server's zone. */
    public function testANaiveInputIsReadAsUtc(): void
    {
        self::assertSame(
            '2026-01-02T03:04:05+00:00',
            DateTimeValue::fromString('2026-01-02 03:04:05')->toIso8601(),
        );
    }

    public function testAnUnparseableInputIsADomainError(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Fecha inválida/');

        DateTimeValue::fromString('not a date at all');
    }

    public function testFromDateTimeImmutableConvertsRatherThanRelabels(): void
    {
        $value = DateTimeValue::fromDateTimeImmutable(
            new \DateTimeImmutable('2026-01-02 04:04:05', new \DateTimeZone('Europe/Madrid')),
        );

        self::assertSame('2026-01-02T03:04:05+00:00', $value->toIso8601());
    }

    public function testNowIsInUtc(): void
    {
        self::assertSame('UTC', DateTimeValue::now()->toDateTimeImmutable()->getTimezone()->getName());
    }

    public function testMinusSecondsMovesBackwards(): void
    {
        $value = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');

        self::assertSame('2026-01-02T02:04:05+00:00', $value->minusSeconds(3600)->toIso8601());
        self::assertSame('2026-01-02T04:04:05+00:00', $value->minusSeconds(-3600)->toIso8601());
    }

    public function testMinusSecondsLeavesTheOriginalAlone(): void
    {
        $value = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
        $value->minusSeconds(3600);

        self::assertSame('2026-01-02T03:04:05+00:00', $value->toIso8601());
    }

    public function testTwoSpellingsOfTheSameInstantAreEqual(): void
    {
        self::assertTrue(
            DateTimeValue::fromString('2026-01-02T03:04:05Z')
                ->equals(DateTimeValue::fromString('2026-01-02T04:04:05+01:00')),
        );
    }

    public function testStringConversionIsTheIsoForm(): void
    {
        self::assertSame(
            '2026-01-02T03:04:05+00:00',
            (string) DateTimeValue::fromString('2026-01-02T03:04:05Z'),
        );
    }
}
