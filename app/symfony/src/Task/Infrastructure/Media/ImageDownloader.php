<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ImageDownloader
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        #[Autowire('%app.ffmpeg_work_dir%/task_images')]
        private readonly string $workDir,
        private readonly HttpClientInterface $httpClient,
        private readonly PublicUrlGuard $urlGuard = new PublicUrlGuard(),
    ) {}

    public function download(string $imageUrl, string $basename): string
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workDir);

        $dst = $this->safeDestination($basename);
        $fs->mkdir(\dirname($dst));

        // Redirects are followed by hand so that every hop is re-checked: a
        // permitted public URL is free to redirect to 169.254.169.254, and
        // letting the HTTP client follow it would defeat the guard entirely.
        $url = $imageUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->urlGuard->assertFetchable($url);

            $options = [
                'max_redirects' => 0,
                'timeout' => 30,
                'max_duration' => 120,
            ];

            // Pin the connection to the address the guard just approved. Without
            // this the client performs its own DNS lookup, and a hostname with a
            // zero TTL can hand the guard a public address and the client a
            // private one - DNS rebinding straight past the check. The hostname
            // stays in the URL so Host, SNI and certificate validation are
            // unaffected.
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
                $options['resolve'] = [$host => $ip];
            }

            $response = $this->httpClient->request('GET', $url, $options);

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;

                if ($location === null) {
                    throw new \RuntimeException('Redirección sin cabecera Location al descargar la imagen.');
                }

                $url = $this->resolveLocation($url, $location);
                continue;
            }

            if ($status !== 200) {
                throw new \RuntimeException(sprintf('No se pudo descargar la imagen: HTTP %d', $status));
            }

            $this->streamToFile($response, $dst);

            return $dst;
        }

        throw BlockedUrl::tooManyRedirects($imageUrl);
    }

    private function streamToFile(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $dst): void
    {
        $handle = fopen($dst, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo escribir la imagen en disco: '.$dst);
        }

        $written = 0;

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();

                if ($content === '') {
                    continue;
                }

                $written += \strlen($content);

                if ($written > self::MAX_BYTES) {
                    throw BlockedUrl::tooLarge(self::MAX_BYTES);
                }

                fwrite($handle, $content);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($dst);

            throw $e;
        }

        fclose($handle);
    }

    /**
     * Keeps the download inside the work directory: a basename containing
     * "../" would otherwise write anywhere the process can reach.
     */
    private function safeDestination(string $basename): string
    {
        $root = rtrim($this->workDir, '/');
        $relative = ltrim(str_replace('\\', '/', $basename), '/');

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new \RuntimeException('Nombre de fichero de imagen inválido: '.$basename);
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
        $location = strtok($location, '#');
        $location = $location === false ? '' : $location;

        if ($location === '') {
            return $currentUrl;
        }

        // Absolute URL.
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $authority = ($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');

        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        // Absolute path.
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.self::normalisePath($location);
        }

        $basePath = $base['path'] ?? '/';

        // Query-only reference: keep the current path.
        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$authority.$basePath.$location;
        }

        // Relative path: resolve against the directory of the current path.
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ($directory === '') {
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

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($out);
                continue;
            }

            $out[] = $segment;
        }

        $resolved = implode('/', $out);

        return ($resolved === '' ? '/' : $resolved).$query;
    }
}
