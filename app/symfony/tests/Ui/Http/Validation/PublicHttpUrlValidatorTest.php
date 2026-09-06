<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Validation;

use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Ui\Http\Validation\PublicHttpUrl;
use App\Ui\Http\Validation\PublicHttpUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The constraint delegates to the same guard the downloads use, so only the
 * glue is checked here: the guard's own rules have their own tests.
 *
 * @extends ConstraintValidatorTestCase<PublicHttpUrlValidator>
 */
final class PublicHttpUrlValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): PublicHttpUrlValidator
    {
        return new PublicHttpUrlValidator(new PublicUrlGuard());
    }

    /** @return iterable<string, array{mixed}> */
    public static function valuesLeftToOtherConstraints(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not text' => [['https://8.8.8.8/hook']];
    }

    #[DataProvider('valuesLeftToOtherConstraints')]
    public function testAbsentOrNonTextValuesAreNotItsBusiness(mixed $value): void
    {
        $this->validator->validate($value, new PublicHttpUrl());

        $this->assertNoViolation();
    }

    public function testAPublicHttpUrlPasses(): void
    {
        $this->validator->validate('https://8.8.8.8/hook', new PublicHttpUrl());

        $this->assertNoViolation();
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedUrls(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'no pública'];
        yield 'loopback' => ['http://127.0.0.1/hook', 'no pública'];
        yield 'private network' => ['http://10.0.0.7/hook', 'no pública'];
        yield 'odd port' => ['http://8.8.8.8:8080/hook', 'Puerto no permitido'];
        yield 'ftp' => ['ftp://8.8.8.8/hook', 'Esquema de URL no permitido'];
        yield 'no host' => ['https:///hook', 'malformada'];
    }

    #[DataProvider('refusedUrls')]
    public function testAUrlTheGuardRefusesIsAViolationSayingWhy(string $url, string $reason): void
    {
        $constraint = new PublicHttpUrl();

        $this->validator->validate($url, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ reason }}', $this->reasonFor($url))
            ->assertRaised();

        self::assertStringContainsString($reason, $this->reasonFor($url));
    }

    private function reasonFor(string $url): string
    {
        try {
            new PublicUrlGuard()->assertFetchable($url);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        self::fail('the guard should have refused '.$url);
    }
}
