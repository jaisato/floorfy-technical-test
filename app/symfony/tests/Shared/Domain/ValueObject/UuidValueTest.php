<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\UuidValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class UuidValueTest extends TestCase
{
    public function testNewIdsAreValidAndDistinct(): void
    {
        $first = UuidValue::new();
        $second = UuidValue::new();

        self::assertTrue(Uuid::isValid($first->value));
        self::assertNotSame($first->value, $second->value);
    }

    /**
     * Time-ordered ids keep inserts at the end of the primary key index instead
     * of scattering them across it.
     */
    public function testNewIdsAreTimeOrdered(): void
    {
        $first = UuidValue::new();
        $second = UuidValue::new();

        self::assertInstanceOf(UuidV7::class, Uuid::fromString($first->value));
        self::assertLessThan(0, strcmp($first->value, $second->value));
    }

    public function testFromStringKeepsTheGivenValue(): void
    {
        $id = UuidValue::fromString('0195c6a0-1c37-7000-8000-000000000000');

        self::assertSame('0195c6a0-1c37-7000-8000-000000000000', $id->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['banana'];
        yield 'truncated' => ['0195c6a0-1c37-7000-8000'];
        yield 'sql injection attempt' => ["' OR 1=1 --"];
    }

    #[DataProvider('invalidIds')]
    public function testFromStringRefusesAnythingThatIsNotAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UuidValue::fromString($value);
    }

    /**
     * For a lookup, "this is not a well-formed id" and "no task has this id"
     * are the same answer, so the read path gets a null instead of an exception
     * that would surface as a 500.
     */
    #[DataProvider('invalidIds')]
    public function testTryFromStringAnswersNullInsteadOfThrowing(string $value): void
    {
        self::assertNull(UuidValue::tryFromString($value));
    }

    public function testTryFromStringAcceptsAWellFormedId(): void
    {
        self::assertNotNull(UuidValue::tryFromString('0195c6a0-1c37-7000-8000-000000000000'));
    }
}
