<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Video;

use App\Task\Domain\Enum\Transition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Stub de Google VEO.
 * Genera un vídeo a partir de una imagen usando FFmpeg + zoom/pan.
 */
final class FfmpegImageAnimator
{
    public function __construct(
        private readonly int $width = 1280,
        private readonly int $height = 720,
        private readonly int $fps = 30,
        private readonly float $durationSeconds = 3.0,
    ) {}

    public function animate(string $imageFile, Transition $transition, string $outputFile): void
    {
        $fs = new Filesystem();
        $fs->mkdir(\dirname($outputFile));

        $frames = (int) round($this->fps * $this->durationSeconds);

        // La imagen a 1280x720 (rellenando o cortando) y aplicamos zoompan.
        $base = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
            $this->width,
            $this->height,
            $this->width,
            $this->height
        );

        $zoompan = $this->zoompanFor($transition, $frames);

        $vf = $base.','.$zoompan;

        $cmd = [
            'ffmpeg',
            '-y',
            '-loop', '1',
            '-i', $imageFile,
            '-t', (string) $this->durationSeconds,
            '-vf', $vf,
            '-r', (string) $this->fps,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputFile,
        ];

        $process = new Process($cmd);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful() || !is_file($outputFile)) {
            throw new \RuntimeException(
                "FFmpeg falló\ncmd=".$process->getCommandLine()."\nstderr=".$process->getErrorOutput()
            );
        }

    }

    private function zoompanFor(Transition $transition, int $frames): string
    {
        $centerX = "iw/2-(iw/zoom/2)";
        $centerY = "ih/2-(ih/zoom/2)";

        return match ($transition) {
            Transition::ZOOM_IN => sprintf(
                "zoompan=z='min(1.0+0.002*on,1.2)':x='%s':y='%s':d=%d:s=%dx%d:fps=%d",
                $centerX,
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
            Transition::ZOOM_OUT => sprintf(
                "zoompan=z='max(1.2-0.002*on,1.0)':x='%s':y='%s':d=%d:s=%dx%d:fps=%d",
                $centerX,
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
            Transition::PAN => sprintf(
                "zoompan=z='1.1':x='max(0,min(iw-(iw/zoom),(iw-(iw/zoom))*on/%d))':y='%s':d=%d:s=%dx%d:fps=%d",
                max(1, $frames),
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
        };
    }
}
