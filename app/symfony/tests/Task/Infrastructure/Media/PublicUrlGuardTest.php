<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Media;

use App\Shared\Domain\Exception\PermanentFailure;
use App\Task\Infrastructure\Media\BlockedUrl;
use App\Task\Infrastructure\Media\PublicTargetPolicy;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Task\Infrastructure\Media\UnresolvableHost;
use App\Task\Infrastructure\Media\UrlNotFetchable;
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
        // Special-purpose IPv4 that a deployment may route internally.
        yield 'test-net-1' => ['http://192.0.2.1/x.png'];
        yield 'test-net-2' => ['http://198.51.100.1/x.png'];
        yield 'test-net-3' => ['http://203.0.113.1/x.png'];
        yield '6to4 relay anycast' => ['http://192.88.99.1/x.png'];
        yield 'broadcast' => ['http://255.255.255.255/x.png'];
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
        // Sitting inside 2000::/3 is not the same as being globally reachable:
        // IANA carves special-purpose blocks out of it. 2001::/23 is blocked
        // whole - every assignment in it is special-purpose and the remainder is
        // unassigned.
        yield 'ipv6 teredo' => ['http://[2001::1]/x.png'];
        yield 'ipv6 pcp anycast' => ['http://[2001:1::1]/x.png'];
        yield 'ipv6 benchmarking' => ['http://[2001:2::1]/x.png'];
        yield 'ipv6 amt' => ['http://[2001:3::1]/x.png'];
        yield 'ipv6 as112' => ['http://[2001:4:112::1]/x.png'];
        yield 'ipv6 orchid' => ['http://[2001:10::1]/x.png'];
        yield 'ipv6 unassigned ietf space' => ['http://[2001:5::1]/x.png'];
        yield 'ipv6 top of ietf block' => ['http://[2001:1ff::1]/x.png'];
        yield 'ipv6 documentation' => ['http://[2001:db8::1]/x.png'];
        yield 'ipv6 direct delegation as112' => ['http://[2620:4f:8000::1]/x.png'];
        yield 'ipv6 documentation 3fff' => ['http://[3fff::1]/x.png'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://evil.example/x'];
        yield 'no scheme' => ['/etc/passwd'];
    }

    #[DataProvider('blockedUrls')]
    public function testInternalAndNonHttpTargetsAreRejected(string $url): void
    {
        $this->expectException(BlockedUrl::class);

        new PublicUrlGuard()->assertFetchable($url);
    }

    /** @return iterable<string, array{string}> */
    public static function allowedUrls(): iterable
    {
        yield 'public ipv4' => ['http://8.8.8.8/photo.jpg'];
        yield 'public ipv4 https' => ['https://1.1.1.1/photo.jpg'];
        yield 'public ipv6' => ['http://[2001:4860:4860::8888]/photo.jpg'];
        yield 'public ipv6 cloudflare' => ['http://[2606:4700:4700::1111]/photo.jpg'];
        // Immediately outside the blocked ranges, to pin the edges.
        yield 'public ipv6 just past the ietf block' => ['http://[2001:200::1]/photo.jpg'];
        yield 'public ipv6 next to as112' => ['http://[2620:4f:7000::1]/photo.jpg'];
    }

    #[DataProvider('allowedUrls')]
    public function testPublicTargetsAreAllowed(string $url): void
    {
        new PublicUrlGuard()->assertFetchable($url);

        $this->addToAssertionCount(1);
    }

    /**
     * The caller has to connect to the address that was checked, not re-resolve
     * the hostname: a name served with a zero TTL can answer with a public
     * address here and a private one at connection time (DNS rebinding).
     */
    public function testReturnsTheValidatedAddressesToPinTheConnectionTo(): void
    {
        $ips = new PublicUrlGuard()->assertFetchable('http://8.8.8.8/photo.jpg');

        self::assertSame(['8.8.8.8'], $ips);
    }

    /**
     * An HTTP service on an odd port is an admin panel, a database's REST front
     * end, a debug listener - not the web URLs this API is meant to fetch.
     */
    public function testOnlyTheWebPortsAreAllowed(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('/Puerto no permitido: 9200/');

        new PublicUrlGuard()->assertFetchable('http://8.8.8.8:9200/_cluster/health');
    }

    /** @return iterable<string, array{string}> */
    public static function allowedPorts(): iterable
    {
        yield 'implicit 80' => ['http://8.8.8.8/photo.jpg'];
        yield 'explicit 80' => ['http://8.8.8.8:80/photo.jpg'];
        yield 'implicit 443' => ['https://8.8.8.8/photo.jpg'];
        yield 'explicit 443' => ['https://8.8.8.8:443/photo.jpg'];
    }

    #[DataProvider('allowedPorts')]
    public function testTheWebPortsAreAllowedWhicheverWayTheyAreWritten(string $url): void
    {
        self::assertNotEmpty(new PublicUrlGuard()->assertFetchable($url));
    }

    /**
     * The policy is a constructor argument so the download tests can reach a
     * server on 127.0.0.1, and for no other reason: nothing in the application
     * passes anything but the default, and there is no environment variable
     * that changes it.
     */
    public function testTheDefaultPolicyIsTheStrictOne(): void
    {
        $policy = new \ReflectionParameter([PublicUrlGuard::class, '__construct'], 'policy');

        self::assertTrue($policy->isDefaultValueAvailable());
        self::assertInstanceOf(PublicTargetPolicy::class, $policy->getDefaultValue());
    }

    /**
     * A name nothing answers for cannot be checked, so it cannot be fetched.
     * .invalid is reserved by RFC 2606 precisely so that it never resolves.
     *
     * Not permanent, though, and that is the whole difference between this and
     * every other refusal here: `dns_get_record()` answers a resolver that
     * timed out exactly as it answers a name that does not exist, so the two
     * are one case and the choice is which way to be wrong about it. Called
     * permanent, a few seconds of resolver trouble failed every task whose
     * image was being fetched during them, with no retry, and marked their
     * callbacks abandoned so the recovery sweep would never offer them again.
     */
    public function testAHostThatCannotBeResolvedIsRefusedButMayBeTriedAgain(): void
    {
        try {
            new PublicUrlGuard()->assertFetchable('https://nothing-answers-for-this.invalid/photo.jpg');
            self::fail('a name that does not resolve cannot be fetched');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(PermanentFailure::class, $e, 'a resolver that was down is a moment, not a verdict');
            self::assertInstanceOf(UrlNotFetchable::class, $e, 'and callers still catch it as one refusal');
            self::assertInstanceOf(UnresolvableHost::class, $e);
            self::assertMatchesRegularExpression('/No se pudo resolver el host/', $e->getMessage());
        }
    }

    /** Everything the policy itself refuses is refused for good. */
    public function testAPolicyRefusalIsPermanent(): void
    {
        try {
            new PublicUrlGuard()->assertFetchable('http://10.0.0.1/photo.jpg');
            self::fail('a private address must be refused');
        } catch (\Throwable $e) {
            self::assertInstanceOf(PermanentFailure::class, $e);
            self::assertInstanceOf(BlockedUrl::class, $e);
        }
    }

    public function testEveryReturnedAddressIsPublic(): void
    {
        $ips = new PublicUrlGuard()->assertFetchable('https://1.1.1.1/photo.jpg');

        self::assertNotEmpty($ips);

        foreach ($ips as $ip) {
            self::assertNotFalse(
                filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE),
                'the guard must never hand back a private or reserved address',
            );
        }
    }
}
