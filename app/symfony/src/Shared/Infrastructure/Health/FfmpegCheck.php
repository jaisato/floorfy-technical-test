<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use App\Shared\Infrastructure\Process\ProcessRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The ffmpeg binary is there and runs.
 *
 * `-version` costs milliseconds and answers the only question worth asking of
 * a container image: an image built without ffmpeg accepts tasks happily and
 * fails every one of them in the worker, minutes later.
 */
final readonly class FfmpegCheck implements HealthCheck
{
    public function __construct(
        private ProcessRunner $processes,
        #[Autowire(param: 'app.health_probe_timeout')]
        private int $timeoutSeconds,
    ) {
    }

    public function name(): string
    {
        return 'ffmpeg';
    }

    public function check(): CheckResult
    {
        $result = $this->processes->run(['ffmpeg', '-hide_banner', '-version'], $this->timeoutSeconds);

        return $result->successful
            ? CheckResult::ok()
            : CheckResult::failed(\sprintf('"%s" salió con %d: %s', $result->commandLine, $result->exitCode, $result->errorOutput));
    }
}
