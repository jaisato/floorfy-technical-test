<?php
declare(strict_types=1);

namespace App\Infrastructure\Composition\Ffmpeg;

use App\Composition\Domain\Port\VideoComposer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FfmpegVideoComposer implements VideoComposer
{
    public function __construct(
        private string $workDir,
    ) {}

    public function compose(array $videoUrls, string $outputBasename): string
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workDir);

        $localFiles = [];
        foreach ($videoUrls as $i => $url) {
            $dst = rtrim($this->workDir, '/')."/{$outputBasename}_part_{$i}.mp4";
            $this->download($url, $dst);
            $localFiles[] = $dst;
        }

        // concat demuxer: requiere lista
        $listFile = rtrim($this->workDir, '/')."/{$outputBasename}_list.txt";
        $content = '';
        foreach ($localFiles as $file) {
            $content .= "file '".str_replace("'", "'\\''", $file)."'\n";
        }
        file_put_contents($listFile, $content);

        $out = rtrim($this->workDir, '/')."/{$outputBasename}_final.mp4";

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $listFile,
            '-c', 'copy',
            $out
        ]);

        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('FFmpeg falló: '.$process->getErrorOutput());
        }

        return $out;
    }

    private function download(string $url, string $dstFile): void
    {
        $fs = new Filesystem();
        $fs->mkdir(\dirname($dstFile));

        $localPath = $this->resolveLocalPath($url);
        if ($localPath !== null) {
            if (!is_file($localPath)) {
                throw new \RuntimeException(sprintf('No existe el fichero local para "%s" (resuelto a "%s")', $url, $localPath));
            }
            $fs->copy($localPath, $dstFile, true);

            return;
        }

        $cmd = ['curl', '-L', '-f', '-sS', $url, '-o', $dstFile];
        $proc = new Process($cmd);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('No se pudo descargar vídeo: ' . $proc->getErrorOutput());
        }
    }

    private function resolveLocalPath(string $url): ?string
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);

            return $path !== '' ? $path : null;
        }

        $parts = @parse_url($url);
        $path = $parts['path'] ?? null;

        if ($path === null) {
            return is_file($url) ? $url : null;
        }

        $publicDir = rtrim((string) (getenv('APP_PUBLIC_DIR') ?: '/var/www/html/public'), '/');

        return $publicDir . $path;
    }
}
