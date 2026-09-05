<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Video;

use App\Shared\Infrastructure\Process\ProcessRunner;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\ImageAnimator;

/**
 * Turns a still image into a short clip with a slow camera move, using ffmpeg's
 * zoompan filter.
 */
final readonly class FfmpegImageAnimator implements ImageAnimator
{
    public function __construct(
        private ProcessRunner $processes,
        private int $timeoutSeconds = 600,
        /** 0 lets ffmpeg decide; set it to cap the CPU one worker can take. */
        private int $threads = 0,
        private int $width = 1280,
        private int $height = 720,
        private int $fps = 30,
        private float $durationSeconds = 3.0,
    ) {
    }

    public function animate(string $imageFile, Transition $transition, string $outputFile): void
    {
        self::ensureDirectoryOf($outputFile);

        $result = $this->processes->run($this->buildCommand($imageFile, $transition, $outputFile), $this->timeoutSeconds);

        if (!$result->successful) {
            throw FfmpegFailed::from('animar la imagen', $result);
        }

        // ffmpeg can exit 0 having written nothing at all (an input it decoded
        // to zero frames, for one); reporting success would hand the composer a
        // part that is not there.
        if (!is_file($outputFile)) {
            throw FfmpegFailed::producedNoOutput('animar la imagen', $result);
        }
    }

    /**
     * The argv ffmpeg is invoked with.
     *
     * Public so the filter graph can be pinned by a test without the binary
     * being installed: this is where the transitions actually live.
     *
     * @return list<string>
     */
    public function buildCommand(string $imageFile, Transition $transition, string $outputFile): array
    {
        // Cover the frame at the requested size, cropping the overflow, before
        // the camera move is applied on top.
        $scale = \sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
            $this->width,
            $this->height,
            $this->width,
            $this->height,
        );

        return [
            'ffmpeg',
            '-y',
            '-nostdin',
            '-loglevel', 'error',
            '-threads', (string) $this->threads,
            '-loop', '1',
            '-i', $imageFile,
            '-t', self::number($this->durationSeconds),
            '-vf', $scale.','.$this->zoompanFor($transition),
            '-r', (string) $this->fps,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputFile,
        ];
    }

    public function frameCount(): int
    {
        return max(1, (int) round($this->fps * $this->durationSeconds));
    }

    private function zoompanFor(Transition $transition): string
    {
        $frames = $this->frameCount();
        $centerX = 'iw/2-(iw/zoom/2)';
        $centerY = 'ih/2-(ih/zoom/2)';

        return match ($transition) {
            Transition::ZOOM_IN => \sprintf(
                "zoompan=z='min(1.0+0.002*on,1.2)':x='%s':y='%s':d=%d:s=%dx%d:fps=%d",
                $centerX,
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
            Transition::ZOOM_OUT => \sprintf(
                "zoompan=z='max(1.2-0.002*on,1.0)':x='%s':y='%s':d=%d:s=%dx%d:fps=%d",
                $centerX,
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
            Transition::PAN => \sprintf(
                "zoompan=z='1.1':x='max(0,min(iw-(iw/zoom),(iw-(iw/zoom))*on/%d))':y='%s':d=%d:s=%dx%d:fps=%d",
                $frames,
                $centerY,
                $frames,
                $this->width,
                $this->height,
                $this->fps,
            ),
        };
    }

    /** Locale-independent: ffmpeg will not read "3,0". */
    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.3F', $value), '0'), '.') ?: '0';
    }

    private static function ensureDirectoryOf(string $file): void
    {
        $directory = \dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('No se pudo crear el directorio "%s".', $directory));
        }
    }
}
