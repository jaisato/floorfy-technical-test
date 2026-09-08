<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, int $timeoutSeconds): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout((float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            // A timeout is a failed run like any other as far as the caller is
            // concerned; what it must not be is an exception escaping from
            // inside a per-partial loop that is meant to keep going.
            return new ProcessResult(false, -1, $process->getCommandLine(), $e->getMessage());
        }

        return new ProcessResult(
            $process->isSuccessful(),
            $process->getExitCode() ?? -1,
            $process->getCommandLine(),
            $process->getErrorOutput(),
        );
    }
}
