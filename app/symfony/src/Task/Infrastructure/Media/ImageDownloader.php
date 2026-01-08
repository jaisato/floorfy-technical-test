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

        $process = new Process([
            'bash',
            '-lc',
            sprintf('curl -L --fail -sS %s -o %s', escapeshellarg($imageUrl), escapeshellarg($dst))
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !is_file($dst)) {
            throw new \RuntimeException('No se pudo descargar la imagen: '.$process->getErrorOutput());
        }

        return $dst;
    }
}
