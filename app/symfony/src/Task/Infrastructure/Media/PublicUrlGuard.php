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
final readonly class PublicUrlGuard
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private TargetPolicy $policy = new PublicTargetPolicy())
    {
    }

    /**
     * @return list<string> every validated address, in resolution order; the
     *                      caller must connect to one of these and to nothing else
     *
     * @throws BlockedUrl       the URL is refused for what it is, every time
     * @throws UnresolvableHost its name did not resolve just now
     */
    public function assertFetchable(string $url): array
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw BlockedUrl::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw BlockedUrl::scheme($scheme);
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];
        if (!$this->policy->allowsPort($port)) {
            throw BlockedUrl::port($port);
        }

        $host = trim($parts['host'], '[]');
        $ips = $this->resolve($host);

        foreach ($ips as $ip) {
            if (!$this->policy->allowsAddress($ip)) {
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
        return $ips;
    }

    /**
     * Every address the host resolves to has to be public: a name that returns
     * both a public and a private record would otherwise slip through.
     *
     * @return list<string>
     *
     * @throws UnresolvableHost when nothing came back, which is a moment rather
     *                          than a verdict - see that class
     */
    private function resolve(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Note: dns_get_record() takes no timeout, so a slow authoritative
        // server is bounded only by the system resolver's own limits. The
        // download budget in ImageDownloader stops a *new* lookup being started
        // once it is spent, but cannot interrupt one already in flight.
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        $ips = [];

        foreach ($records ?: [] as $record) {
            if (isset($record['ip']) && \is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ([] === $ips) {
            // dns_get_record() does not consult /etc/hosts, so names resolved
            // locally (starting with "localhost") never reach the range check
            // without this fallback.
            //
            // gethostbynamel(), not gethostbyname(): the singular form returns
            // only the first record, and the contract above is that *every*
            // address the name resolves to has been checked. Vetting one while
            // the client may reach another is the same hole as not checking.
            $resolved = gethostbynamel($host);

            foreach (\is_array($resolved) ? $resolved : [] as $address) {
                if ($address !== $host) {
                    $ips[] = $address;
                }
            }
        }

        if ([] === $ips) {
            throw UnresolvableHost::host($host);
        }

        return $ips;
    }
}
