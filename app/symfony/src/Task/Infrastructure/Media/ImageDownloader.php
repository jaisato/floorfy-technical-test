<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ImageDownloader
{
    public function __construct(
        #[Autowire('%app.ffmpeg_work_dir%/task_images')]
        private readonly string $workDir,
    ) {}

    public function download(string $imageUrl, string $basename): string
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workDir);

        $dst = rtrim($this->workDir, '/').'/'.ltrim($basename, '/');
        $fs->mkdir(\dirname($dst));

        if (str_starts_with($imageUrl, 'file://')) {
            $src = substr($imageUrl, 7);
            if ($src === '' || !is_file($src)) {
                throw new \RuntimeException('Imagen file:// inválida: '.$imageUrl);
            }
            $fs->copy($src, $dst, true);
            return $dst;
        }

        $this->assertPublicHttpUrl($imageUrl);

        $process = new Process([
            'bash',
            '-lc',
            sprintf(
                "curl -L --fail -sS --proto '=http,https' --proto-redir '=http,https' %s -o %s",
                escapeshellarg($imageUrl),
                escapeshellarg($dst)
            )
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !is_file($dst)) {
            throw new \RuntimeException('No se pudo descargar la imagen: '.$process->getErrorOutput());
        }

        return $dst;
    }

    /**
     * Prevents SSRF: only allow http/https URLs whose host resolves exclusively
     * to public IP addresses (blocks loopback, private, link-local/metadata and
     * other reserved ranges, e.g. 127.0.0.1, 10.0.0.0/8, 169.254.169.254).
     */
    private function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('URL de imagen no permitida: '.$url);
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
            if ($ips === []) {
                $resolved = gethostbyname($host);
                if ($resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
        }

        if ($ips === []) {
            throw new \RuntimeException('No se pudo resolver el host de la imagen: '.$host);
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('La URL de la imagen apunta a una red no permitida: '.$url);
            }
        }
    }
}
