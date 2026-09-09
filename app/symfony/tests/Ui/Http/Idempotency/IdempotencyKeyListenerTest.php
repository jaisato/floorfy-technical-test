<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Idempotency;

use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryIdempotencyStore;
use App\Ui\Http\Exception\HttpProblem;
use App\Ui\Http\Idempotency\IdempotencyKeyListener;
use App\Ui\Http\Idempotency\RequestFingerprint;
use App\Ui\Http\Idempotency\StoredResponse;
use App\Ui\Http\RequestActor;
use App\Ui\Http\Response\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * The listener alone, with the store in memory: which requests it intercepts,
 * and what each outcome of a claim turns into.
 */
final class IdempotencyKeyListenerTest extends TestCase
{
    private const int TTL = 3600;

    private InMemoryIdempotencyStore $store;
    private FixedClock $clock;
    private IdempotencyKeyListener $listener;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdempotencyStore();
        $this->clock = new FixedClock();
        $this->listener = new IdempotencyKeyListener($this->store, new RequestActor(new TokenStorage()), $this->clock, self::TTL);
    }

    public function testARequestWithoutTheHeaderIsLeftAlone(): void
    {
        $event = $this->request(self::post('{"a":1}', key: null));

        $this->listener->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $this->store->records);
    }

    /** Only the routes that create something honour the header. */
    public function testAnotherRouteIsLeftAlone(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $request->attributes->set('_route', 'api_tasks_list');

        $event = $this->request($request);
        $this->listener->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $this->store->records);
    }

    public function testAFreeKeyIsClaimedAndTheRequestRuns(): void
    {
        $event = $this->request(self::post('{"a":1}', key: 'k-1'));

        $this->listener->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertArrayHasKey('anonymous/k-1', $this->store->records);
        self::assertSame(['anonymous/k-1/token-1'], $this->store->began, 'the unit of work opens with the claim');
    }

    public function testTheResponseOfAClaimedKeyIsStored(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));

        $this->listener->onResponse($this->response($request, new Response('{"task_id":"x"}', Response::HTTP_CREATED, ['Content-Type' => 'application/json'])));

        $stored = $this->store->records['anonymous/k-1']['response'];
        self::assertInstanceOf(StoredResponse::class, $stored);
        self::assertSame(Response::HTTP_CREATED, $stored->status);
        self::assertSame('{"task_id":"x"}', $stored->body);
        self::assertSame('application/json', $stored->contentType);
    }

    public function testTheSameRequestIsAnsweredWithTheStoredResponse(): void
    {
        $this->answered('k-1', '{"a":1}', new Response('{"task_id":"x"}', Response::HTTP_CREATED, ['Content-Type' => 'application/json']));

        $event = $this->request(self::post('{"a":1}', key: 'k-1'));
        $this->listener->onRequest($event);

        $replay = $event->getResponse();
        self::assertInstanceOf(Response::class, $replay);
        self::assertSame(Response::HTTP_CREATED, $replay->getStatusCode());
        self::assertSame('{"task_id":"x"}', $replay->getContent());
        self::assertSame('true', $replay->headers->get(IdempotencyKeyListener::REPLAYED_HEADER));
    }

    /** A key names one request; a second, different one under it is a mistake. */
    public function testTheSameKeyWithADifferentBodyIsRefused(): void
    {
        $this->answered('k-1', '{"a":1}', new Response('{}', Response::HTTP_CREATED));

        $this->assertRefused(Response::HTTP_UNPROCESSABLE_ENTITY, self::post('{"a":2}', key: 'k-1'));
    }

    public function testTheSameKeyWhileTheFirstRequestIsStillRunningIsRefused(): void
    {
        // Claimed but never answered: exactly the state a second, concurrent
        // request finds while the first one is in the handler.
        $this->listener->onRequest($this->request(self::post('{"a":1}', key: 'k-1')));

        $this->assertRefused(Response::HTTP_CONFLICT, self::post('{"a":1}', key: 'k-1'));
    }

    /**
     * The record is dropped once it expires, so the key is free again and the
     * request runs for real instead of replaying a day-old answer.
     */
    public function testAKeyIsFreeAgainOnceItsRecordExpires(): void
    {
        $this->answered('k-1', '{"a":1}', new Response('{"task_id":"x"}', Response::HTTP_CREATED));

        $this->clock->advance(self::TTL + 1);

        $event = $this->request(self::post('{"a":1}', key: 'k-1'));
        $this->listener->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertNull($this->store->records['anonymous/k-1']['response']);
    }

    /** Our fault, not the client's: the retry has to run for real. */
    public function testAServerErrorGivesTheKeyBack(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));

        $this->listener->onResponse($this->response($request, new Response('{}', Response::HTTP_INTERNAL_SERVER_ERROR)));

        self::assertSame(['anonymous/k-1'], $this->store->released);
        self::assertSame([], $this->store->records);
    }

    /**
     * A refusal the client caused is an answer like any other: repeating the
     * same bad request under the same key must not create a task either.
     */
    public function testAClientErrorIsStoredLikeAnyOtherAnswer(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));

        $this->listener->onResponse($this->response($request, new Response('{}', Response::HTTP_BAD_REQUEST)));

        $stored = $this->store->records['anonymous/k-1']['response'];
        self::assertInstanceOf(StoredResponse::class, $stored);
        self::assertSame(Response::HTTP_BAD_REQUEST, $stored->status);
    }

    public function testAResponseWithoutAClaimStoresNothing(): void
    {
        $this->listener->onResponse($this->response(self::post('{"a":1}', key: null), new Response()));

        self::assertSame([], $this->store->records);
        self::assertSame([], $this->store->released);
    }

    /**
     * A request that outlived the store's grace comes back to a claim that is
     * no longer its own - the client's retry took it - and however late, its
     * answer must not be stored over the retry's, nor its failure release the
     * retry's key.
     */
    public function testAnAnswerFromAnAttemptThatWasTakenOverIsNotStored(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));
        $this->store->takeOver('anonymous', 'k-1');

        $event = $this->response($request, new Response('{"task_id":"late"}', Response::HTTP_CREATED));
        $this->listener->onResponse($event);

        self::assertNull($this->store->records['anonymous/k-1']['response']);
        // And the client is not told of a task the store rolled back.
        self::assertSame(Response::HTTP_CONFLICT, $event->getResponse()->getStatusCode());
        self::assertSame(ApiProblem::CONTENT_TYPE, $event->getResponse()->headers->get('Content-Type'));
    }

    public function testAFailureOfAnAttemptThatWasTakenOverReleasesNothing(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));
        $this->store->takeOver('anonymous', 'k-1');

        $this->listener->onResponse($this->response($request, new Response('{}', Response::HTTP_INTERNAL_SERVER_ERROR)));

        self::assertSame([], $this->store->released);
        self::assertArrayHasKey('anonymous/k-1', $this->store->records);
    }

    /**
     * A request that ends without any response - an exception the kernel was
     * told not to catch - answered nothing: the key goes back when the request
     * finishes, and the client's retry runs for real.
     */
    public function testARequestThatEndsWithoutAResponseGivesTheKeyBack(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));

        $this->listener->onFinishRequest($this->finish($request));

        self::assertSame(['anonymous/k-1'], $this->store->released);
    }

    /** And one that did answer is left alone when it finishes. */
    public function testAnAnsweredRequestIsLeftAloneWhenItFinishes(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));
        $this->listener->onResponse($this->response($request, new Response('{}', Response::HTTP_CREATED)));

        $this->listener->onFinishRequest($this->finish($request));

        self::assertSame([], $this->store->released);
        self::assertInstanceOf(StoredResponse::class, $this->store->records['anonymous/k-1']['response']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'too long' => [str_repeat('k', IdempotencyKeyListener::MAX_KEY_LENGTH + 1)];
        yield 'newline' => ["a\nb"];
        yield 'non-ascii' => ['clave-ñ'];
    }

    #[DataProvider('unusableKeys')]
    public function testAKeyThatIsNotUsableIsRefused(string $key): void
    {
        $this->assertRefused(Response::HTTP_BAD_REQUEST, self::post('{"a":1}', key: $key));
    }

    /** The listener refuses by throwing; the status it names is the contract. */
    private function assertRefused(int $status, Request $request): void
    {
        try {
            $this->listener->onRequest($this->request($request));
        } catch (HttpProblem $problem) {
            self::assertSame($status, $problem->getStatusCode());
            self::assertNotSame('', $problem->getMessage());

            return;
        }

        self::fail(\sprintf('Se esperaba un HttpProblem %d.', $status));
    }

    private function answered(string $key, string $body, Response $response): void
    {
        $request = self::post($body, key: $key);
        $this->listener->onRequest($this->request($request));
        $this->listener->onResponse($this->response($request, $response));
    }

    private static function post(string $body, ?string $key): Request
    {
        $headers = null === $key ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];
        $request = Request::create('/api/tasks', 'POST', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->attributes->set('_route', 'api_tasks_create');

        return $request;
    }

    private function request(Request $request): RequestEvent
    {
        return new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function response(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function finish(Request $request): FinishRequestEvent
    {
        return new FinishRequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    /** The fingerprint is what the listener stores; this pins the pairing. */
    public function testTheStoredFingerprintIsTheRequestFingerprint(): void
    {
        $request = self::post('{"a":1}', key: 'k-1');
        $this->listener->onRequest($this->request($request));

        self::assertSame(RequestFingerprint::of($request), $this->store->records['anonymous/k-1']['fingerprint']);
    }
}
