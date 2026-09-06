<?php

declare(strict_types=1);

namespace App\Ui\Http\RateLimit;

use App\Ui\Http\RequestActor;
use App\Ui\Http\Response\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Caps how often one caller may create tasks.
 *
 * Creating a task is the one request that costs real money: it buys minutes of
 * ffmpeg on a shared worker, and a client in a retry loop can fill the queue
 * for everybody else. Reads are cheap and are not limited.
 *
 * Off by default (RATE_LIMIT_TASK_CREATION=0), and when it is off the limiter is
 * never even asked - a deployment that does not want this must not pay for a
 * cache round trip per request to find that out.
 *
 * The window is per caller: the authenticated client's name where there is one,
 * the IP otherwise. Anything else would let one noisy client throttle the rest.
 */
final readonly class CreateTaskRateLimitListener
{
    /** Only the route that starts work; the rest of the API is cheap. */
    private const string LIMITED_ROUTE = 'api_tasks_create';

    public function __construct(
        #[Target('taskCreationLimiter')]
        private RateLimiterFactoryInterface $limiter,
        private RequestActor $actor,
        #[Autowire(env: 'int:RATE_LIMIT_TASK_CREATION')]
        private int $limit,
    ) {
    }

    /**
     * Below the firewall's 8, so the caller is authenticated and the window is
     * the client's own rather than everyone behind one address - at 8 this ran
     * *before* the firewall, registration order deciding it, and every
     * authenticated caller was counted by IP - and below the Idempotency-Key
     * listener's 4, so a replay, which creates nothing, spends nothing. A
     * request that gets this far is one that would create a task; the key
     * claimed for it is released again when this refuses it.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($this->limit <= 0 || !$event->isMainRequest() || self::LIMITED_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $limit = $this->limiter->create($this->keyFor($request))->consume();
        $headers = [
            'X-RateLimit-Limit' => (string) $limit->getLimit(),
            'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
            'X-RateLimit-Reset' => (string) $limit->getRetryAfter()->getTimestamp(),
        ];

        if ($limit->isAccepted()) {
            // So a well-behaved client can slow down before it is refused.
            $request->attributes->set('_rate_limit_headers', $headers);

            return;
        }

        $event->setResponse(ApiProblem::response(
            Response::HTTP_TOO_MANY_REQUESTS,
            'Se han creado demasiadas tareas en poco tiempo; inténtalo de nuevo más tarde.',
            [],
            $headers + ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
        ));
    }

    /** Copies the counters onto the response of a request that was let through. */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getRequest()->attributes->get('_rate_limit_headers');

        if (!\is_array($headers)) {
            return;
        }

        foreach ($headers as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $event->getResponse()->headers->set($name, $value);
            }
        }
    }

    private function keyFor(Request $request): string
    {
        return $this->actor->identifier() ?? 'ip:'.($request->getClientIp() ?? 'unknown');
    }
}
