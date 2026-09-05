<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Video;

use App\Shared\Infrastructure\Process\ProcessRunner;
use App\Task\Domain\Port\VideoComposer;

/**
 * Concatenates the parts of a task with ffmpeg's concat demuxer.
 *
 * Stream copy, no re-encode: every part was produced by this application with
 * the same codec and parameters, so there is nothing to transcode.
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

    public function compose(array $localFiles, string $outputFile): void
    {
        if ([] === $localFiles) {
            throw new \RuntimeException('No hay vídeos parciales que concatenar.');
        }

        self::ensureDirectory($this->workDir);
        self::ensureDirectory(\dirname($outputFile));

        $listFile = rtrim($this->workDir, '/').'/concat_'.bin2hex(random_bytes(8)).'.txt';

        if (false === file_put_contents($listFile, self::buildConcatList($localFiles))) {
            throw new \RuntimeException(\sprintf('No se pudo escribir la lista de concatenación en "%s".', $listFile));
        }

        try {
            $result = $this->processes->run($this->buildCommand($listFile, $outputFile), $this->timeoutSeconds);

            if (!$result->successful) {
                throw FfmpegFailed::from('concatenar los vídeos parciales', $result);
            }

            if (!is_file($outputFile)) {
                throw FfmpegFailed::producedNoOutput('concatenar los vídeos parciales', $result);
            }
        } finally {
            // Whatever happened, the scratch file does not outlive the call:
            // one list per attempt times twenty retries times every task adds
            // up on a volume nothing ever cleans.
            @unlink($listFile);
        }
    }

    /**
     * The argv ffmpeg is invoked with.
     *
     * @return list<string>
     */
    public function buildCommand(string $listFile, string $outputFile): array
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
     * The concat demuxer's input format.
     *
     * Each entry is single-quoted, and a single quote inside a path is written
     * as '\'' - closing the quote, escaping one, reopening. Getting this wrong
     * on a path the application chose is unlikely; getting it wrong silently is
     * how a file is read from somewhere else entirely.
     *
     * @param list<string> $localFiles
     */
    public static function buildConcatList(array $localFiles): string
    {
        $lines = '';

        foreach ($localFiles as $file) {
            $lines .= "file '".str_replace("'", "'\\''", $file)."'\n";
        }

        return $lines;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('No se pudo crear el directorio "%s".', $directory));
        }
    }
}
