<?php

declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Shared\Application\Health\ReadinessProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and readiness, the two questions an orchestrator asks.
 *
 * They are different questions and must not share an answer. Liveness is "is
 * this process still a process": if it fails, restarting helps. Readiness is
 * "can it serve traffic right now": if it fails because the database is down,
 * restarting helps nothing and only makes the outage worse. Docker restarts on
 * an unhealthy container, so its healthcheck asks the second one and a load
 * balancer takes the instance out instead.
 *
 * Both stay public when the API key is switched on: a prober has no token.
 */
#[AsController]
final readonly class HealthController
{
    public function __construct(private ReadinessProbe $probe)
    {
    }

    #[Route('/health', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $readiness = $this->probe->run();

        return new JsonResponse(
            ['status' => $readiness->ready ? 'ok' : 'unavailable', 'checks' => $readiness->toArray()],
            $readiness->ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            // Never a cached answer: a stale "ok" is the one thing this endpoint
            // must not produce.
            ['Cache-Control' => 'no-store'],
        );
    }
}
