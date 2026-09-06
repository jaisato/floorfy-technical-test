<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\ApiTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTokensTest extends TestCase
{
    /** The default: an unset variable must not turn authentication on or off by accident. */
    public function testNoTokensLeavesTheApiOpen(): void
    {
        $tokens = new ApiTokens('');

        self::assertFalse($tokens->enabled());
        self::assertNull($tokens->clientFor('anything'));
    }

    public function testWhitespaceOnlyIsStillNoTokens(): void
    {
        self::assertFalse(new ApiTokens('  ,  ')->enabled());
    }

    public function testASecretResolvesToItsClientName(): void
    {
        $tokens = new ApiTokens('web:secret-of-sixteen,batch:another-long-secret');

        self::assertTrue($tokens->enabled());
        self::assertSame('web', $tokens->clientFor('secret-of-sixteen'));
        self::assertSame('batch', $tokens->clientFor('another-long-secret'));
    }

    public function testAnUnknownSecretResolvesToNobody(): void
    {
        self::assertNull(new ApiTokens('web:secret-of-sixteen')->clientFor('secret-of-sixteem'));
    }

    /** The name is not a credential; only the secret is. */
    public function testTheClientNameIsNotAcceptedAsASecret(): void
    {
        self::assertNull(new ApiTokens('web:secret-of-sixteen')->clientFor('web'));
    }

    public function testSpacesAroundAnEntryAreIgnored(): void
    {
        self::assertSame('web', new ApiTokens(" web:secret-of-sixteen , \n batch:another-long-secret ")->clientFor('secret-of-sixteen'));
    }

    /** A colon is legal inside a secret; only the first one separates. */
    public function testASecretMayContainAColon(): void
    {
        self::assertSame('web', new ApiTokens('web:aaaa:bbbb:cccc:dddd')->clientFor('aaaa:bbbb:cccc:dddd'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenConfigurations(): iterable
    {
        yield 'no separator' => ['websecretofsixteen', 'nombre:secreto'];
        yield 'no name' => [':secret-of-sixteen', 'nombre:secreto'];
        yield 'short secret' => ['web:too-short', 'menos de 16'];
        yield 'shared secret' => ['web:secret-of-sixteen,batch:secret-of-sixteen', 'mismo secreto'];
    }

    /**
     * A deployment that meant to switch authentication on and got the syntax
     * wrong must not come up quietly open.
     */
    #[DataProvider('brokenConfigurations')]
    public function testABrokenConfigurationIsRefused(string $tokens, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expectedMessage, '/').'/');

        new ApiTokens($tokens);
    }

    public function testTheMinimumSecretLengthIsExactlyThat(): void
    {
        $short = str_repeat('a', ApiTokens::MINIMUM_SECRET_LENGTH - 1);
        $exact = str_repeat('a', ApiTokens::MINIMUM_SECRET_LENGTH);

        self::assertSame('web', new ApiTokens('web:'.$exact)->clientFor($exact));

        $this->expectException(\InvalidArgumentException::class);
        new ApiTokens('web:'.$short);
    }
}
