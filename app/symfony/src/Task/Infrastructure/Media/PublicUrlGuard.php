<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

/**
 * Rejects URLs that point back inside the infrastructure.
 *
 * Image URLs arrive straight from the public API, so without this check the
 * service will happily fetch http://169.254.169.254/... (cloud instance
 * metadata), http://localhost:<port>/ (anything bound on the box) or an
 * address on the private network, and surface the outcome through the task's
 * error message. That is a server-side request forgery primitive.
 */
final class PublicUrlGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * IPv4/IPv6 ranges that must never be reachable through a user-supplied
     * URL. FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE cover most of this, but
     * not the cloud metadata address, which is the one that matters most.
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /**
     * @return string the validated address the caller must connect to
     *
     * @throws BlockedUrl
     */
    public function assertFetchable(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw BlockedUrl::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw BlockedUrl::scheme($scheme);
        }

        $host = trim($parts['host'], '[]');
        $ips = $this->resolve($host);

        foreach ($ips as $ip) {
            if (!$this->isPublic($ip)) {
                throw BlockedUrl::privateAddress($host, $ip);
            }
        }

        // Returning the address matters: if the caller hands the *hostname* to
        // an HTTP client, the client resolves it again, and a name served with
        // a zero TTL can answer with a public address here and a private one
        // there (DNS rebinding). The connection has to be pinned to an address
        // that was actually checked.
        return $ips[0];
    }

    /**
     * Every address the host resolves to has to be public: a name that returns
     * both a public and a private record would otherwise slip through.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];

        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            // dns_get_record() does not consult /etc/hosts, so names resolved
            // locally (starting with "localhost") never reach the range check
            // without this fallback.
            $resolved = gethostbyname($host);

            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        if ($ips === []) {
            throw BlockedUrl::unresolvable($host);
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as [$network, $bits]) {
                if ($this->inV4Range($ip, $network, $bits)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6 is checked on the packed bytes, never on the text. The same
        // address has many spellings - "::1" and "0:0:0:0:0:0:0:1" are the same
        // host - so a string comparison misses every form but the one it was
        // written for, and the caller chooses the spelling.
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // Loopback ::1 and unspecified ::
        if ($packed === str_repeat("\0", 15)."\1" || $packed === str_repeat("\0", 16)) {
            return false;
        }

        $first = ord($packed[0]);

        // Unique-local fc00::/7
        if (($first & 0xFE) === 0xFC) {
            return false;
        }

        // Link-local fe80::/10
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return false;
        }

        // Multicast ff00::/8
        if ($first === 0xFF) {
            return false;
        }

        // IPv4-mapped ::ffff:a.b.c.d and IPv4-compatible ::a.b.c.d both carry an
        // IPv4 address in the last four bytes; it has to face the IPv4 rules.
        $isMapped = str_starts_with($packed, str_repeat("\0", 10)."\xFF\xFF");
        $isCompatible = str_starts_with($packed, str_repeat("\0", 12));

        if ($isMapped || $isCompatible) {
            $embedded = inet_ntop(substr($packed, 12));

            return is_string($embedded) && $this->isPublic($embedded);
        }

        return true;
    }

    private function inV4Range(string $ip, string $network, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);

        if ($ipLong === false || $netLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
