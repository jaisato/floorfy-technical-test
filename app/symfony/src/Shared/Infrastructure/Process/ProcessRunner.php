<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

/**
 * Runs an external command.
 *
 * The ffmpeg adapters build an argv array and hand it here, which is what lets
 * their command construction be tested without the binary being installed.
 */
interface ProcessRunner
{
    /**
     * @param list<string> $command argv, the program first; never a shell string
     */
    public function run(array $command, int $timeoutSeconds): ProcessResult;
}
