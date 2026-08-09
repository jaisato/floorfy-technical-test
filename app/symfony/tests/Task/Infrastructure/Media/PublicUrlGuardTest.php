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
        // The same host has many spellings, and the caller picks one. A textual
        // comparison against "::1" misses every expanded form.
        yield 'ipv6 loopback expanded' => ['http://[0:0:0:0:0:0:0:1]:8000/admin'];
        yield 'ipv6 loopback zero padded' => ['http://[0000:0000:0000:0000:0000:0000:0000:0001]/x.png'];
        yield 'ipv6 unspecified' => ['http://[::]/x.png'];
        yield 'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/x.png'];
        yield 'ipv4 mapped private' => ['http://[::ffff:10.0.0.1]/x.png'];
        yield 'ipv4 compatible loopback' => ['http://[::127.0.0.1]/x.png'];
        yield 'ipv6 unique local' => ['http://[fc00::1]/x.png'];
        yield 'ipv6 link local' => ['http://[fe80::1]/x.png'];
        yield 'ipv6 multicast' => ['http://[ff02::1]/x.png'];
        // Deny-by-default: only global unicast 2000::/3 is allowed, so a
        // special-purpose range nobody enumerated is refused rather than
        // treated as public.
        yield 'ipv6 site local (deprecated)' => ['http://[fec0::1]/admin'];
        yield 'ipv6 site local expanded' => ['http://[fec0:0:0:0:0:0:0:1]/admin'];
        yield 'ipv6 discard only' => ['http://[100::1]/x.png'];
        // Tunnels carrying an arbitrary IPv4 destination inside a global-looking
        // address.
        yield 'ipv6 6to4 tunnel' => ['http://[2002:7f00:1::1]/x.png'];
        yield 'ipv6 teredo tunnel' => ['http://[2001:0:1::1]/x.png'];
        yield 'ipv6 documentation range' => ['http://[2001:db8::1]/x.png'];
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
        yield 'public ipv6' => ['http://[2001:4860:4860::8888]/photo.jpg'];
        yield 'public ipv6 cloudflare' => ['http://[2606:4700:4700::1111]/photo.jpg'];
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
    public function testReturnsTheValidatedAddressesToPinTheConnectionTo(): void
    {
        $ips = (new PublicUrlGuard())->assertFetchable('http://8.8.8.8/photo.jpg');

        self::assertSame(['8.8.8.8'], $ips);
    }

    public function testEveryReturnedAddressIsPublic(): void
    {
        $ips = (new PublicUrlGuard())->assertFetchable('https://1.1.1.1/photo.jpg');

        self::assertNotEmpty($ips);

        foreach ($ips as $ip) {
            self::assertNotFalse(
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE),
                'the guard must never hand back a private or reserved address',
            );
        }
    }
}
