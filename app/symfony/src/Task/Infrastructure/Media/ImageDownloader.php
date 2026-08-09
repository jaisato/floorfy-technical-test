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
            $this->urlGuard->assertFetchable($url);

            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 30,
                'max_duration' => 120,
            ]);

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

    private function resolveLocation(string $currentUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($currentUrl);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base.'/'.ltrim($location, '/');
    }
}
