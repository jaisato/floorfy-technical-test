<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every readiness check and logs why any of them failed.
 *
 * A check that throws is a failed check, not a 500: the point of this endpoint
 * is to answer even when the things it asks about are broken.
 */
final readonly class ReadinessProbe
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(
        #[AutowireIterator('app.health_check')]
        private iterable $checks,
        private LoggerInterface $logger,
    ) {
    }

    public function run(): Readiness
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[$check->name()] = $check->check();
            } catch (\Throwable $e) {
                $results[$check->name()] = CheckResult::failed($e->getMessage());
            }
        }

        ksort($results);

        $readiness = new Readiness($results);

        if (!$readiness->ready) {
            $this->logger->error('Readiness check failed', $readiness->failures());
        }

        return $readiness;
    }
}
