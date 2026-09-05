<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

/**
 * Only globally routable addresses, only on the ports the web uses.
 */
final class PublicTargetPolicy implements TargetPolicy
{
    /**
     * Anything else is a service that happens to speak HTTP on an odd port -
     * an admin panel, a database's REST front end, a debug listener. The web
     * URLs this API is meant to fetch live on 80 and 443.
     */
    private const array ALLOWED_PORTS = [80, 443];

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
    private const array BLOCKED_V4 = [
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
     * Special-purpose IPv6 blocks that sit inside global unicast 2000::/3, from
     * the IANA IPv6 Special-Purpose Address Registry. Being in 2000::/3 does
     * not by itself mean an address is globally reachable.
     */
    private const array BLOCKED_V6 = [
        // The whole IETF Protocol Assignments block. Everything assigned inside
        // it is special-purpose (Teredo, PCP/TURN anycast, benchmarking, AMT,
        // AS112, ORCHID, drone remote ID) and the remainder is unassigned, so
        // blocking the parent covers the lot - including ranges that get
        // assigned later, which enumerating the children one at a time does not.
        ['2001::', 23],
        ['2001:db8::', 32],       // documentation
        ['2002::', 16],           // 6to4
        ['2620:4f:8000::', 48],   // direct delegation AS112
        ['3fff::', 20],           // documentation
    ];

    public function allowsPort(int $port): bool
    {
        return \in_array($port, self::ALLOWED_PORTS, true);
    }

    public function allowsAddress(string $ip): bool
    {
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_V4 as [$network, $bits]) {
                if (self::inV4Range($ip, $network, $bits)) {
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
        if (false === $packed || 16 !== \strlen($packed)) {
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

            return \is_string($embedded) && $this->allowsAddress($embedded);
        }

        // Deny by default: only global unicast 2000::/3 is routable on the
        // internet, so that is the allow-list rather than a list of ranges to
        // block - a special-purpose range nobody enumerated must not default to
        // "public".
        if ((\ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }

        // Membership of 2000::/3 is necessary but not sufficient: IANA carves
        // special-purpose blocks out of it that are not globally reachable.
        foreach (self::BLOCKED_V6 as [$prefix, $bits]) {
            if (self::inV6Range($packed, $prefix, $bits)) {
                return false;
            }
        }

        return true;
    }

    /** Compares the first $bits of a packed IPv6 address against a prefix. */
    private static function inV6Range(string $packed, string $prefix, int $bits): bool
    {
        $prefixPacked = @inet_pton($prefix);

        if (false === $prefixPacked || 16 !== \strlen($prefixPacked)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);

        if ($fullBytes > 0 && 0 !== strncmp($packed, $prefixPacked, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;

        if (0 === $remainingBits) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (\ord($packed[$fullBytes]) & $mask) === (\ord($prefixPacked[$fullBytes]) & $mask);
    }

    private static function inV4Range(string $ip, string $network, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);

        if (false === $ipLong || false === $netLong) {
            return false;
        }

        $mask = 0 === $bits ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
