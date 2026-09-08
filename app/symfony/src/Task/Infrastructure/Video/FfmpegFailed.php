<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Video;

use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\Exception\HasOperatorDetail;
use App\Shared\Infrastructure\Process\ProcessResult;

/**
 * An ffmpeg invocation that did not produce its output.
 *
 * The message is short on purpose - it is stored on the task and served to API
 * clients - while the command line and everything ffmpeg wrote to stderr are
 * kept separately, for the log.
 */
final class FfmpegFailed extends \RuntimeException implements ClientSafe, HasOperatorDetail
{
    private function __construct(
        string $message,
        private readonly string $commandLine,
        private readonly string $errorOutput,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function operatorDetail(): array
    {
        return ['command' => $this->commandLine, 'stderr' => $this->errorOutput];
    }

    public static function from(string $what, ProcessResult $result): self
    {
        return new self(
            \sprintf('FFmpeg no pudo %s (código de salida %d).', $what, $result->exitCode),
            $result->commandLine,
            $result->errorOutput,
        );
    }

    public static function producedNoOutput(string $what, ProcessResult $result): self
    {
        return new self(
            \sprintf('FFmpeg terminó sin generar el fichero al %s.', $what),
            $result->commandLine,
            $result->errorOutput,
        );
    }
}
