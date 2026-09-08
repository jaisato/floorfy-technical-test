<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

final readonly class ProcessResult
{
    public function __construct(
        public bool $successful,
        public int $exitCode,
        public string $commandLine,
        public string $errorOutput,
    ) {
    }
}
