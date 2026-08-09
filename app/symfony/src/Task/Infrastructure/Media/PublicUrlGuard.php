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
     * Every IPv4 range IANA marks as special-purpose (RFC 6890 and its
     * updates), i.e. not globally reachable.
     *
     * IPv4 has no single "global unicast" prefix to allow-list the way
     * 2000::/3 works for IPv6, so the deny-by-default intent is expressed by
     * enumerating the special-purpose space exhaustively rather than listing
     * only the ranges that came to mind. Anything left over is genuinely
     * routable address space.
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],           // this network
        ['10.0.0.0', 8],          // private
        ['100.64.0.0', 10],       // carrier-grade NAT
        ['127.0.0.0', 8],         // loopback
        ['169.254.0.0', 16],      // link-local, incl. cloud metadata
        ['172.16.0.0', 12],       // private
        ['192.0.0.0', 24],        // IETF protocol assignments
        ['192.0.2.0', 24],        // TEST-NET-1
        ['192.31.196.0', 24],     // AS112-v4
        ['192.52.193.0', 24],     // AMT
        ['192.88.99.0', 24],      // 6to4 relay anycast (deprecated)
        ['192.168.0.0', 16],      // private
        ['192.175.48.0', 24],     // direct delegation AS112
        ['198.18.0.0', 15],       // benchmarking
        ['198.51.100.0', 24],     // TEST-NET-2
        ['203.0.113.0', 24],      // TEST-NET-3
        ['224.0.0.0', 4],         // multicast
        ['240.0.0.0', 4],         // reserved, incl. 255.255.255.255
    ];

    /**
     * @return list<string> every validated address, in resolution order; the
     *                      caller must connect to one of these and to nothing else
     *
     * @throws BlockedUrl
     */
    public function assertFetchable(string $url): array
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

        // Returning the addresses matters: if the caller hands the *hostname*
        // to an HTTP client, the client resolves it again, and a name served
        // with a zero TTL can answer with a public address here and a private
        // one there (DNS rebinding). The connection has to be pinned to an
        // address that was actually checked.
        //
        // All of them are returned rather than just the first: a host with
        // several records expects the client to fail over between them, and
        // handing back one address would turn a single unreachable endpoint
        // into a failed download.
        return array_values($ips);
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

        // IPv4-mapped ::ffff:a.b.c.d and IPv4-compatible ::a.b.c.d both carry an
        // IPv4 address in the last four bytes; it has to face the IPv4 rules.
        $isMapped = str_starts_with($packed, str_repeat("\0", 10)."\xFF\xFF");
        $isCompatible = str_starts_with($packed, str_repeat("\0", 12));

        if ($isMapped || $isCompatible) {
            $embedded = inet_ntop(substr($packed, 12));

            return is_string($embedded) && $this->isPublic($embedded);
        }

        $first = ord($packed[0]);

        // Everything below is deny-by-default. Listing the ranges to block and
        // allowing the rest means any special-purpose range not thought of -
        // deprecated site-local fec0::/10, for one - is treated as a public
        // address. Only global unicast 2000::/3 is routable on the internet, so
        // that is the allow-list.
        if (($first & 0xE0) !== 0x20) {
            return false;
        }

        $second = ord($packed[1]);

        // 2002::/16 (6to4) and 2001::/32 (Teredo) are tunnelling mechanisms
        // that carry an arbitrary IPv4 destination - including a private one -
        // inside an address that otherwise looks global.
        if ($first === 0x20 && $second === 0x02) {
            return false;
        }

        if ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x00 && ord($packed[3]) === 0x00) {
            return false;
        }

        // 2001:db8::/32 is reserved for documentation and never routes.
        if ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x0D && ord($packed[3]) === 0xB8) {
            return false;
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
