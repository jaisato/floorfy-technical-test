<?php
declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Media;

use App\Task\Infrastructure\Media\BlockedUrl;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Image URLs come straight from the public API, so this guard is the only
 * thing standing between a caller and the machine's own network.
 */
final class PublicUrlGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback' => ['http://127.0.0.1:8000/admin'];
        yield 'localhost' => ['http://localhost/'];
        yield 'private 10/8' => ['http://10.1.2.3/x.png'];
        yield 'private 172.16/12' => ['http://172.16.5.5/x.png'];
        yield 'private 192.168/16' => ['http://192.168.1.5/x.png'];
        yield 'carrier grade nat' => ['http://100.64.0.1/x.png'];
        yield 'ipv6 loopback' => ['http://[::1]/x.png'];
        yield 'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/x.png'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://evil.example/x'];
        yield 'no scheme' => ['/etc/passwd'];
    }

    #[DataProvider('blockedUrls')]
    public function testInternalAndNonHttpTargetsAreRejected(string $url): void
    {
        $this->expectException(BlockedUrl::class);

        (new PublicUrlGuard())->assertFetchable($url);
    }

    /** @return iterable<string, array{string}> */
    public static function allowedUrls(): iterable
    {
        yield 'public ipv4' => ['http://8.8.8.8/photo.jpg'];
        yield 'public ipv4 https' => ['https://1.1.1.1/photo.jpg'];
    }

    #[DataProvider('allowedUrls')]
    public function testPublicTargetsAreAllowed(string $url): void
    {
        (new PublicUrlGuard())->assertFetchable($url);

        $this->addToAssertionCount(1);
    }

    /**
     * The caller has to connect to the address that was checked, not re-resolve
     * the hostname: a name served with a zero TTL can answer with a public
     * address here and a private one at connection time (DNS rebinding).
     */
    public function testReturnsTheValidatedAddressToPinTheConnectionTo(): void
    {
        $ip = (new PublicUrlGuard())->assertFetchable('http://8.8.8.8/photo.jpg');

        self::assertSame('8.8.8.8', $ip);
    }

    public function testReturnedAddressIsAlwaysPublic(): void
    {
        $ip = (new PublicUrlGuard())->assertFetchable('https://1.1.1.1/photo.jpg');

        self::assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
        self::assertFalse(
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false,
            'the guard must never hand back a private or reserved address',
        );
    }
}
