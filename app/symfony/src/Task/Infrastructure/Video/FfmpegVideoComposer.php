<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Video;

use App\Shared\Infrastructure\Process\ProcessRunner;
use App\Task\Domain\Port\VideoComposer;
use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;

/**
 * Concatenates the parts of a task with ffmpeg.
 *
 * Two ways, depending on what the task asked for:
 *
 *   - no crossfade: the concat demuxer with `-c copy`. Every part was produced
 *     here with the same codec and parameters, so there is nothing to
 *     transcode and the join costs a file copy.
 *   - a crossfade: an xfade chain. Blending two pictures means decoding and
 *     re-encoding them, so this is the expensive path, and it is only taken
 *     when it was asked for. A hard cut is the default.
 */
final readonly class FfmpegVideoComposer implements VideoComposer
{
    public function __construct(
        private ProcessRunner $processes,
        private string $workDir,
        private int $timeoutSeconds = 3600,
        private int $threads = 0,
    ) {
    }

    public function compose(array $clips, string $outputFile, RenderOptions $options): void
    {
        if ([] === $clips) {
            throw new \RuntimeException('No hay vídeos parciales que concatenar.');
        }

        self::ensureDirectory($this->workDir);
        self::ensureDirectory(\dirname($outputFile));

        // One part cannot cross-fade with itself: with a single clip there is
        // nothing to blend, and the cheap path produces exactly the same file.
        if ($options->hasCrossfade() && \count($clips) > 1) {
            $this->run($this->buildCrossfadeCommand($clips, $outputFile, $options), $outputFile);

            return;
        }

        $listFile = rtrim($this->workDir, '/').'/concat_'.bin2hex(random_bytes(8)).'.txt';

        if (false === file_put_contents($listFile, self::buildConcatList($clips))) {
            throw new \RuntimeException(\sprintf('No se pudo escribir la lista de concatenación en "%s".', $listFile));
        }

        try {
            $this->run($this->buildConcatCommand($listFile, $outputFile), $outputFile);
        } finally {
            // Whatever happened, the scratch file does not outlive the call:
            // one list per attempt times twenty retries times every task adds
            // up on a volume nothing ever cleans.
            @unlink($listFile);
        }
    }

    /**
     * The argv of the cheap path: stream copy through the concat demuxer.
     *
     * @return list<string>
     */
    public function buildConcatCommand(string $listFile, string $outputFile): array
    {
        return [
            'ffmpeg',
            '-y',
            '-nostdin',
            '-loglevel', 'error',
            '-threads', (string) $this->threads,
            '-f', 'concat',
            // The list holds absolute paths, which the demuxer refuses without this.
            '-safe', '0',
            '-i', $listFile,
            '-c', 'copy',
            $outputFile,
        ];
    }

    /**
     * The argv of the crossfade path: every clip as its own input, chained
     * through xfade.
     *
     * Each fade starts at the point where the picture so far runs out, less the
     * length of the fade: offset(k) = sum of the k clips before it, minus k
     * fades, because every fade already consumed one of them. Getting this
     * wrong does not fail - it produces a video with the transitions in the
     * wrong places - which is why it is pinned by a test.
     *
     * @param list<Clip> $clips
     *
     * @return list<string>
     */
    public function buildCrossfadeCommand(array $clips, string $outputFile, RenderOptions $options): array
    {
        $fade = self::fadeFor($clips, $options);
        $command = ['ffmpeg', '-y', '-nostdin', '-loglevel', 'error', '-threads', (string) $this->threads];

        foreach ($clips as $clip) {
            $command[] = '-i';
            $command[] = $clip->file;
        }

        $steps = [];
        $previous = '[0:v]';
        $elapsed = 0.0;

        for ($i = 1, $count = \count($clips); $i < $count; ++$i) {
            $elapsed += $clips[$i - 1]->durationSeconds;
            $label = \sprintf('[v%d]', $i);

            $steps[] = \sprintf(
                '%s[%d:v]xfade=transition=fade:duration=%s:offset=%s%s',
                $previous,
                $i,
                self::number($fade),
                self::number(max(0.0, $elapsed - $i * $fade)),
                $label,
            );

            $previous = $label;
        }

        $command[] = '-filter_complex';
        $command[] = implode(';', $steps);
        $command[] = '-map';
        $command[] = $previous;

        return [
            ...$command,
            '-r', (string) $options->fps,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputFile,
        ];
    }

    /**
     * The fade actually used: never longer than half the shortest clip.
     *
     * A two-second fade between one-second clips is not a transition, it is a
     * command ffmpeg refuses. The request is honoured as far as the material
     * allows rather than rejected, because the ceiling depends on per-image
     * durations the caller may not have thought about.
     *
     * @param list<Clip> $clips
     */
    public static function fadeFor(array $clips, RenderOptions $options): float
    {
        if ([] === $clips) {
            return 0.0;
        }

        $shortest = min(array_map(static fn (Clip $clip): float => $clip->durationSeconds, $clips));

        return min($options->crossfade, $shortest / 2);
    }

    /**
     * The concat demuxer's input format.
     *
     * Each entry is single-quoted, and a single quote inside a path is written
     * as '\'' - closing the quote, escaping one, reopening. Getting this wrong
     * on a path the application chose is unlikely; getting it wrong silently is
     * how a file is read from somewhere else entirely.
     *
     * @param list<Clip> $clips
     */
    public static function buildConcatList(array $clips): string
    {
        $lines = '';

        foreach ($clips as $clip) {
            $lines .= "file '".str_replace("'", "'\\''", $clip->file)."'\n";
        }

        return $lines;
    }

    /** @param list<string> $command */
    private function run(array $command, string $outputFile): void
    {
        $result = $this->processes->run($command, $this->timeoutSeconds);

        if (!$result->successful) {
            throw FfmpegFailed::from('concatenar los vídeos parciales', $result);
        }

        if (!is_file($outputFile)) {
            throw FfmpegFailed::producedNoOutput('concatenar los vídeos parciales', $result);
        }
    }

    /** Locale-independent: ffmpeg will not read "0,5". */
    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.3F', $value), '0'), '.') ?: '0';
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('No se pudo crear el directorio "%s".', $directory));
        }
    }
}
