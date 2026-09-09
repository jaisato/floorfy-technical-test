<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

use App\Shared\Application\Clock\Clock;
use App\Ui\Http\Exception\HttpProblem;
use App\Ui\Http\RequestActor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 *   - same key while the first request is still running: 409. A request that
 *     died without answering looks the same, and is told apart by its age:
 *     past the store's grace the key is taken over and the retry runs for
 *     real, rather than being refused until the key's TTL. Should the first
 *     request turn out to be alive after all and come back to answer, it
 *     finds a claim that is no longer its own, and stores nothing.
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
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Below the firewall's 8, so the caller is authenticated and keys are
     * scoped to a real client name rather than to everyone sharing an address,
     * and above the rate limiter's 2, so a replay is answered without spending
     * any of the caller's quota.
     *
     * That second half matters more than it looks. A client repeats a request
     * precisely because it never saw the answer - the timeout it just suffered
     * is the reason it is retrying - and charging that retry to the quota let a
     * lost response push a well-behaved client over its limit and turn a replay
     * into a 429. A replay creates nothing, so it owes nothing. The other way
     * round, the limiter is still in front of every request that could create a
     * task: a key nobody has used falls straight through to it.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
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
                $request->attributes->set(self::ATTRIBUTE, [$scope, $key, $result->token]);

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

        if (!\is_array($claim) || 3 !== \count($claim) || !\is_string($claim[0]) || !\is_string($claim[1]) || !\is_string($claim[2])) {
            return;
        }

        [$scope, $key, $token] = $claim;
        $response = $event->getResponse();

        if (self::isRetryable($response->getStatusCode())) {
            $this->store->release($scope, $key, $token);

            return;
        }

        if (!$this->store->complete($scope, $key, $token, StoredResponse::fromResponse($response))) {
            // This request outlived the store's grace and the client's retry
            // took its key: the client has the retry's answer, or will, and
            // this one answers nothing it can still ask. What this request
            // created, if anything, is a duplicate the client is never told
            // of - the exposure DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS
            // describes - and this line is where to find it.
            $this->logger->warning('Idempotency-Key claim was taken over before this response could be stored; the retry that took it answers the client.', [
                'scope' => $scope,
                'key' => $key,
                'status' => $response->getStatusCode(),
            ]);
        }
    }

    private static function isIdempotentRoute(Request $request): bool
    {
        return \in_array($request->attributes->get('_route'), self::IDEMPOTENT_ROUTES, true);
    }

    /**
     * Whether an answer invites the client to come back rather than settling
     * the request, in which case the key must not be pinned to it.
     *
     * A 5xx is something that broke on our side. A 429 is the rate limiter,
     * which now runs after this listener and so can refuse a request whose key
     * is already claimed: stored, that 429 would be replayed for the whole TTL
     * and the task would never be created however long the client waited.
     * Every other answer, a validation error included, is this request's final
     * one and is what a repeat of it deserves to be told again.
     */
    private static function isRetryable(int $status): bool
    {
        return $status >= Response::HTTP_INTERNAL_SERVER_ERROR || Response::HTTP_TOO_MANY_REQUESTS === $status;
    }
}
