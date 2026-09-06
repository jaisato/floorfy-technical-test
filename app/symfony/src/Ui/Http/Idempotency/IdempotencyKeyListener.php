<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

use App\Shared\Application\Clock\Clock;
use App\Ui\Http\Exception\HttpProblem;
use App\Ui\Http\RequestActor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Idempotency-Key support for POST /api/tasks.
 *
 * A client that lost the answer to a request (timeout, dropped connection)
 * repeats it with the same key and gets the original response back instead of
 * a second task:
 *
 *   - first request with a key: the key is claimed, the request runs, the
 *     response is stored (any status below 500; a 5xx releases the key so the
 *     retry runs for real);
 *   - identical request, same key: the stored response is replayed, marked
 *     with Idempotency-Replayed: true;
 *   - different request, same key: 422 - a key names one request only;
 *   - same key while the first request is still running: 409.
 *
 * Keys are scoped per caller so two clients cannot collide, and expire after
 * the configured TTL.
 */
final readonly class IdempotencyKeyListener
{
    public const string HEADER = 'Idempotency-Key';
    public const string REPLAYED_HEADER = 'Idempotency-Replayed';
    public const int MAX_KEY_LENGTH = 255;

    private const string ATTRIBUTE = '_idempotency_claim';

    /** The routes that honour the header. */
    private const array IDEMPOTENT_ROUTES = ['api_tasks_create'];

    public function __construct(
        private IdempotencyStore $store,
        private RequestActor $actor,
        private Clock $clock,
        #[Autowire(param: 'app.idempotency_ttl_seconds')]
        private int $ttlSeconds,
    ) {
    }

    /**
     * After the router (priority 32) and the firewall (8), so the route is
     * known and so is the caller.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !self::isIdempotentRoute($request) || !$request->headers->has(self::HEADER)) {
            return;
        }

        $key = (string) $request->headers->get(self::HEADER);

        if ('' === $key || \strlen($key) > self::MAX_KEY_LENGTH || 1 !== preg_match('/^[\x21-\x7E]+$/', $key)) {
            throw new HttpProblem(Response::HTTP_BAD_REQUEST, \sprintf('La cabecera %s debe tener entre 1 y %d caracteres ASCII imprimibles.', self::HEADER, self::MAX_KEY_LENGTH));
        }

        $scope = $this->actor->scope();
        $result = $this->store->claim($scope, $key, RequestFingerprint::of($request), $this->clock->now(), $this->ttlSeconds);

        switch ($result->outcome) {
            case ClaimOutcome::CLAIMED:
                $request->attributes->set(self::ATTRIBUTE, [$scope, $key]);

                return;

            case ClaimOutcome::REPLAY:
                $stored = $result->response;

                if (null === $stored) {
                    throw new \LogicException('A replay must carry the stored response.');
                }

                $event->setResponse(new Response($stored->body, $stored->status, [
                    'Content-Type' => $stored->contentType,
                    self::REPLAYED_HEADER => 'true',
                ]));

                return;

            case ClaimOutcome::MISMATCH:
                throw new HttpProblem(Response::HTTP_UNPROCESSABLE_ENTITY, \sprintf('La cabecera %s ya se usó con una petición distinta.', self::HEADER));
            case ClaimOutcome::IN_PROGRESS:
                throw new HttpProblem(Response::HTTP_CONFLICT, \sprintf('Una petición con esta %s todavía se está procesando.', self::HEADER));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $claim = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (!\is_array($claim) || 2 !== \count($claim) || !\is_string($claim[0]) || !\is_string($claim[1])) {
            return;
        }

        [$scope, $key] = $claim;
        $response = $event->getResponse();

        if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            // Something broke on our side; the client's retry should run for real.
            $this->store->release($scope, $key);

            return;
        }

        $this->store->complete($scope, $key, StoredResponse::fromResponse($response));
    }

    private static function isIdempotentRoute(Request $request): bool
    {
        return \in_array($request->attributes->get('_route'), self::IDEMPOTENT_ROUTES, true);
    }
}
