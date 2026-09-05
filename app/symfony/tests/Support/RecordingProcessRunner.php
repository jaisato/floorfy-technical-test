<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Process\ProcessResult;
use App\Shared\Infrastructure\Process\ProcessRunner;

/**
 * Records what would have been executed and answers whatever the test wants,
 * so the ffmpeg adapters can be exercised on a machine with no ffmpeg.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, timeout: int}> */
    public array $runs = [];

    private bool $successful = true;
    private string $errorOutput = '';

    /** @var callable(list<string>): void|null */
    private $sideEffect;

    public function willFail(string $errorOutput = 'ffmpeg: something went wrong'): void
    {
        $this->successful = false;
        $this->errorOutput = $errorOutput;
    }

    /** @param callable(list<string>): void $sideEffect */
    public function onRun(callable $sideEffect): void
    {
        $this->sideEffect = $sideEffect;
    }

    public function run(array $command, int $timeoutSeconds): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'timeout' => $timeoutSeconds];

        if (null !== $this->sideEffect) {
            ($this->sideEffect)($command);
        }

        return new ProcessResult(
            $this->successful,
            $this->successful ? 0 : 1,
            implode(' ', $command),
            $this->errorOutput,
        );
    }

    /** @return list<string> */
    public function lastCommand(): array
    {
        $last = $this->runs[array_key_last($this->runs) ?? 0] ?? null;

        if (null === $last) {
            throw new \LogicException('nothing was run');
        }

        return $last['command'];
    }
}
