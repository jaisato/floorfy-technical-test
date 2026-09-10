<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use App\Task\Domain\Port\ImageFetcher;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class ImageDownloader implements ImageFetcher
{
    public const int DEFAULT_MAX_BYTES = 50 * 1024 * 1024;

    private const int MAX_REDIRECTS = 5;
    private const int CONNECT_TIMEOUT_SECONDS = 30;

    /**
     * The statuses that always carry a Location a GET can follow, so one
     * without it is an error. 300 is followed only when it names a preferred
     * choice in Location (RFC 9110 section 15.4.1) and is otherwise an answer
     * in its own right; 304 never carries one. Treating the whole 3xx range as
     * a redirect turned those two into a bogus "missing Location" error.
     */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * Wall-clock budget for the whole download, redirects and address retries
     * included. Handing each attempt its own budget let one task hold a worker
     * for addresses x redirects x budget.
     */
    private const int MAX_TOTAL_SECONDS = 120;

    private string $imagesDir;

    public function __construct(
        string $workDir,
        private HttpClientInterface $httpClient,
        private PublicUrlGuard $urlGuard = new PublicUrlGuard(),
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        $this->imagesDir = rtrim($workDir, '/').'/images';
    }

    public function fetch(string $imageUrl, string $relativeName): string
    {
        $destination = $this->safeDestination($relativeName);
        self::ensureDirectory(\dirname($destination));

        // Redirects are followed by hand so that every hop is re-checked: a
        // permitted public URL is free to redirect to 169.254.169.254, and
        // letting the HTTP client follow it would defeat the guard entirely.
        $url = $imageUrl;
        $deadline = microtime(true) + self::MAX_TOTAL_SECONDS;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            // Checked before the guard, not after: assertFetchable() resolves
            // DNS, and a hop that starts with no budget left would otherwise
            // block in the resolver before anything noticed the deadline.
            self::assertBudgetRemains($deadline);

            $ips = $this->urlGuard->assertFetchable($url);

            $response = $this->requestPinnedToAValidatedAddress($url, $ips, $deadline);

            $status = $response->getStatusCode();

            $location = self::locationOf($response);

            if (self::isRedirect($status, $location)) {
                if (null === $location) {
                    throw new \RuntimeException('Redirección sin cabecera Location al descargar la imagen.');
                }

                $next = $this->resolveLocation($url, $location);

                // A Location that resolves back to the current URL (a lone
                // fragment, "?" with the same query) would otherwise re-issue
                // the identical request until the hop limit ran out: six
                // requests and a DNS lookup each, for one submitted URL.
                if ($next === $url) {
                    throw new \RuntimeException('La redirección apunta a la misma URL; se descarta para no repetirla.');
                }

                $url = $next;
                continue;
            }

            // Any 2xx, not just 200: the `curl -L --fail` this replaced failed
            // on server errors, not on a successful status that happens not to
            // be 200. A transforming proxy answering 203, or a 206 from a range
            // request, still delivers the image.
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(\sprintf('No se pudo descargar la imagen: HTTP %d', $status));
            }

            $headers = $response->getHeaders(false);
            self::assertAdvertisedTypeIsAnImage($headers);
            $this->assertAdvertisedSizeFits($headers);

            $written = $this->streamToFile($response, $destination);

            // 204/205, or any 2xx with nothing behind it, would otherwise leave
            // a zero-byte file that ffmpeg later fails on for no clear reason.
            if (0 === $written) {
                @unlink($destination);

                throw new \RuntimeException(\sprintf('La respuesta HTTP %d no contenía imagen alguna.', $status));
            }

            $this->assertContentIsAnImage($destination);

            return $destination;
        }

        throw BlockedUrl::tooManyRedirects($imageUrl);
    }

    /**
     * The Location header, or null when it is absent or blank: a blank one is
     * no Location, and resolved as a reference it named the current URL and
     * re-requested it until the hop limit.
     */
    private static function locationOf(ResponseInterface $response): ?string
    {
        $location = $response->getHeaders(false)['location'][0] ?? null;

        if (null === $location || '' === trim($location)) {
            return null;
        }

        return $location;
    }

    private static function isRedirect(int $status, ?string $location): bool
    {
        return \in_array($status, self::REDIRECT_STATUSES, true) || (300 === $status && null !== $location);
    }

    /**
     * Issues the request against one of the addresses the guard approved.
     *
     * Pinning matters: handing the client the hostname would let it perform its
     * own DNS lookup, and a name served with a zero TTL can answer with a public
     * address for the guard and a private one for the connection - DNS
     * rebinding straight past the check. The hostname stays in the URL, so Host,
     * SNI and certificate validation are unaffected.
     *
     * Every validated address is tried before giving up, which is the failover a
     * client would normally do across a host's A/AAAA records; pinning to just
     * the first would turn one unreachable endpoint into a failed download.
     *
     * @param list<string> $ips
     */
    private function requestPinnedToAValidatedAddress(string $url, array $ips, float $deadline): ResponseInterface
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $needsPinning = \is_string($host) && false === filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP);

        if (!$needsPinning) {
            $response = $this->httpClient->request('GET', $url, $this->budgetedOptions($deadline));
            $response->getStatusCode();

            return $response;
        }

        $lastError = null;

        foreach ($ips as $ip) {
            // Outside the try below: an exhausted budget is a plain
            // RuntimeException the transport catch would not hold, and letting
            // it escape from inside discarded the real transport error the
            // earlier addresses had produced.
            try {
                $budgeted = $this->budgetedOptions($deadline);
            } catch (\RuntimeException $e) {
                throw $lastError ?? $e;
            }

            try {
                $options = $budgeted + ['resolve' => [$host => $ip]];
                $response = $this->httpClient->request('GET', $url, $options);

                // Force the transport to connect now, so an unreachable address
                // fails here and the next one gets its turn.
                $response->getStatusCode();

                return $response;
            } catch (TransportExceptionInterface $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('No se pudo conectar con ninguna dirección validada para '.$url);
    }

    /**
     * Options carrying whatever is left of the shared budget, so retries and
     * redirects cannot each start the clock again.
     *
     * @return array<string,mixed>
     */
    private function budgetedOptions(float $deadline): array
    {
        $remaining = self::assertBudgetRemains($deadline);

        return [
            'max_redirects' => 0,
            'timeout' => min(self::CONNECT_TIMEOUT_SECONDS, $remaining),
            'max_duration' => $remaining,
            // Without this the client honours http_proxy / HTTP_PROXY /
            // all_proxy from the environment, and a proxy resolves the hostname
            // itself: the `resolve` pin is silently discarded and the address
            // the guard validated is not the one contacted. That is the DNS
            // rebinding hole the pin exists to close, reopened by an environment
            // variable. Passing 'proxy' => null does not disable it; only
            // no_proxy does.
            'no_proxy' => '*',
        ];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function assertAdvertisedTypeIsAnImage(array $headers): void
    {
        $contentType = $headers['content-type'][0] ?? null;

        // A missing Content-Type is not proof of anything either way; the sniff
        // after the body has been written is what actually decides.
        if (null === $contentType) {
            return;
        }

        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        // application/octet-stream is what an object store serves when nobody
        // set a type on the upload, and refusing it would reject a large share
        // of perfectly good image URLs. It says "unknown bytes", so it is left
        // to the sniff rather than trusted or rejected on the spot.
        if (str_starts_with($mediaType, 'image/') || 'application/octet-stream' === $mediaType) {
            return;
        }

        throw BlockedUrl::notAnImage($mediaType);
    }

    /**
     * Refuses an oversized body before a single byte of it is read. The
     * streaming cap still applies - Content-Length is a claim, not a fact - but
     * an honest server is taken at its word and the transfer never starts.
     *
     * @param array<string, list<string>> $headers
     */
    private function assertAdvertisedSizeFits(array $headers): void
    {
        $length = $headers['content-length'][0] ?? null;

        if (null === $length || 1 !== preg_match('/^\d+$/', $length)) {
            return;
        }

        if ((int) $length > $this->maxBytes) {
            throw BlockedUrl::tooLarge($this->maxBytes);
        }
    }

    /**
     * What was actually written decides, not what the server said it was
     * sending: the file is about to be handed to ffmpeg.
     */
    private function assertContentIsAnImage(string $file): void
    {
        $mediaType = @mime_content_type($file);

        if (\is_string($mediaType) && str_starts_with($mediaType, 'image/')) {
            return;
        }

        @unlink($file);

        throw BlockedUrl::notAnImage(\is_string($mediaType) ? $mediaType : '');
    }

    /**
     * @return int bytes written
     *
     * Written to a unique temporary file and renamed into place. The transport
     * retries a task and the handler re-enters one that is not finished, so two
     * runs can be handed the same name; writing straight to the final path let
     * one truncate or interleave with the other's file, and a killed process
     * left a half-written image where a reader expects a complete one. rename()
     * is atomic within a filesystem: a reader sees the previous file or a whole
     * new one, never something in between.
     */
    private function streamToFile(ResponseInterface $response, string $destination): int
    {
        $temporary = $destination.'.'.bin2hex(random_bytes(8)).'.part';
        $handle = fopen($temporary, 'w');

        if (false === $handle) {
            throw new \RuntimeException('No se pudo escribir la imagen en disco: '.$destination);
        }

        $written = 0;

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();

                if ('' === $content) {
                    continue;
                }

                $written += \strlen($content);

                if ($written > $this->maxBytes) {
                    throw BlockedUrl::tooLarge($this->maxBytes);
                }

                // fwrite() returns false on failure and can report a short write
                // when the disk is full or a quota is hit. Ignoring it means
                // returning a truncated image as a successful download.
                self::writeAll($handle, $content);
            }

            // Inside the try: a deferred write error can surface at close time
            // on a network filesystem, and closing outside meant that failure
            // went unnoticed and skipped the cleanup below.
            if (!fclose($handle)) {
                throw new \RuntimeException('No se pudo cerrar el fichero de imagen: '.$destination);
            }
        } catch (\Throwable $e) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);

            throw $e;
        }

        if (!rename($temporary, $destination)) {
            @unlink($temporary);

            throw new \RuntimeException('No se pudo mover la imagen descargada a su destino: '.$destination);
        }

        return $written;
    }

    /**
     * @param resource $handle
     */
    private static function writeAll($handle, string $content): void
    {
        $length = \strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $bytes = fwrite($handle, substr($content, $offset));

            if (false === $bytes || 0 === $bytes) {
                throw new \RuntimeException('No se pudo escribir la imagen completa en disco.');
            }

            $offset += $bytes;
        }
    }

    /**
     * @return float seconds left of the shared budget
     *
     * Note the resolver itself is not bounded by this: PHP's dns_get_record()
     * takes no timeout, so a lookup already in flight runs to the system
     * resolver's own limit. What this does guarantee is that no new hop, lookup
     * or request is started once the budget is gone.
     */
    private static function assertBudgetRemains(float $deadline): float
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new \RuntimeException('Se agotó el tiempo máximo de descarga de la imagen.');
        }

        return $remaining;
    }

    /**
     * Keeps the download inside the work directory: a name containing "../"
     * would otherwise write anywhere the process can reach.
     */
    private function safeDestination(string $relativeName): string
    {
        $root = rtrim($this->imagesDir, '/');
        $relative = ltrim(str_replace('\\', '/', $relativeName), '/');

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                continue;
            }
            $segments[] = $segment;
        }

        if ([] === $segments) {
            throw new \RuntimeException('Nombre de fichero de imagen inválido: '.$relativeName);
        }

        return $root.'/'.implode('/', $segments);
    }

    /**
     * Resolves a Location header against the current URL following the
     * URI-reference rules of RFC 3986 section 5.
     *
     * Treating every non-absolute Location as host-relative is wrong for the
     * common cases: "next.jpg" sent from https://example.com/images/source
     * belongs under /images/, "?page=2" keeps the current path, and
     * "//cdn.example.com/x" is a different host, not a path.
     */
    private function resolveLocation(string $currentUrl, string $location): string
    {
        $location = trim($location);

        // Position-based, not strtok(): strtok skips leading delimiters, so a
        // fragment-only "#frag" came back as "frag" and was followed as a
        // sibling path. A fragment-only reference keeps the current URL, and
        // fragments are never sent in the request anyway.
        $hash = strpos($location, '#');
        if (false !== $hash) {
            $location = substr($location, 0, $hash);
        }

        if ('' === $location) {
            return $currentUrl;
        }

        // Absolute URL. parse_url() answers false, not null, for a malformed
        // reference such as "///x", and a `!== null` test sent those down this
        // branch to fail one hop later with a misleading message.
        if (\is_string(parse_url($location, \PHP_URL_SCHEME))) {
            return $location;
        }

        $base = parse_url($currentUrl);
        $base = \is_array($base) ? $base : [];
        $scheme = \is_string($base['scheme'] ?? null) ? $base['scheme'] : 'https';

        // The authority includes userinfo. Rebuilding it from host and port
        // alone silently drops the credentials in
        // https://user:pass@example.com/..., so a relative redirect on an
        // authenticated URL would come back 401.
        $userInfo = '';
        if (isset($base['user'])) {
            $userInfo = $base['user'].(isset($base['pass']) ? ':'.$base['pass'] : '').'@';
        }

        $authority = $userInfo.($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');

        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        // Absolute path.
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.self::normalisePath($location);
        }

        $basePath = \is_string($base['path'] ?? null) ? $base['path'] : '/';

        // Query-only reference: keep the current path.
        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$authority.$basePath.$location;
        }

        // Relative path: resolve against the directory of the current path.
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ('' === $directory) {
            $directory = '/';
        }

        return $scheme.'://'.$authority.self::normalisePath($directory.$location);
    }

    /** Collapses "." and ".." segments, per RFC 3986 section 5.2.4. */
    private static function normalisePath(string $path): string
    {
        $query = '';
        if (($pos = strpos($path, '?')) !== false) {
            $query = substr($path, $pos);
            $path = substr($path, 0, $pos);
        }

        $segments = explode('/', $path);
        $lastIndex = \count($segments) - 1;
        $out = [];

        foreach ($segments as $index => $segment) {
            if ('.' === $segment || '..' === $segment) {
                if ('..' === $segment && \count($out) > 1) {
                    array_pop($out);
                }

                // A trailing "." or ".." denotes a directory: "Location: ." from
                // /images/source resolves to /images/, not /images. Dropping the
                // segment without putting the slash back requests a different
                // path, which servers that canonicalise directory URLs answer
                // with another redirect.
                if ($index === $lastIndex) {
                    $out[] = '';
                }

                continue;
            }

            $out[] = $segment;
        }

        $resolved = implode('/', $out);

        return ('' === $resolved ? '/' : $resolved).$query;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('No se pudo crear el directorio "%s".', $directory));
        }
    }
}
