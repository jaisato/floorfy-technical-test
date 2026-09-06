<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;

/**
 * A directory the worker has to write into exists and is writable.
 *
 * Registered twice, once per directory: the published videos and the scratch
 * space. Both are Docker volumes, and a volume created before the image set
 * their ownership is the classic way for every render to fail at the last step.
 *
 * The directory is created if it is missing - that is what the worker does too,
 * and a check that reports "not writable" for a path nobody has made yet would
 * fail a container that is perfectly healthy.
 */
final readonly class WritableDirectoryCheck implements HealthCheck
{
    public function __construct(
        private string $checkName,
        private string $directory,
    ) {
    }

    public function name(): string
    {
        return $this->checkName;
    }

    public function check(): CheckResult
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return CheckResult::failed(\sprintf('No se pudo crear el directorio "%s".', $this->directory));
        }

        if (!is_writable($this->directory)) {
            return CheckResult::failed(\sprintf('El directorio "%s" no es escribible.', $this->directory));
        }

        return CheckResult::ok();
    }
}
