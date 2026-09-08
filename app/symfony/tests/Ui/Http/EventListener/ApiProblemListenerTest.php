<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\EventListener;

use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Tests\Support\RecordingLogger;
use App\Ui\Http\EventListener\ApiProblemListener;
use App\Ui\Http\Response\ApiProblem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class ApiProblemListenerTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testARouterNotFoundBecomesAProblemDocument(): void
    {
        $event = $this->handle('/api/tasks/nope', new NotFoundHttpException('No route found for "GET /api/tasks/nope"'));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(ApiProblem::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'No hay ningún recurso en esa dirección.',
        ], $this->body($response));
    }

    /** The header the router produced is what tells the client what to try. */
    public function testTheAllowHeaderSurvives(): void
    {
        $event = $this->handle('/api/tasks', new MethodNotAllowedHttpException(['GET', 'POST']));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    /**
     * The message of an unexpected exception is where a driver puts the host it
     * could not reach. It goes to the log, never to the client.
     */
    public function testAnUnexpectedExceptionIsGenericToTheClientAndDetailedInTheLog(): void
    {
        $event = $this->handle('/api/tasks', new \RuntimeException('SQLSTATE[HY000]: connection to 10.0.0.5 refused'));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringNotContainsString('10.0.0.5', (string) $response->getContent());
        self::assertSame('La petición no se pudo procesar.', $this->body($response)['detail']);

        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('10.0.0.5', $this->logger->everythingLogged());
    }

    public function testAHandledClientErrorIsNotLoggedAsAFailure(): void
    {
        $this->handle('/api/tasks/nope', new NotFoundHttpException());

        self::assertSame([], $this->logger->records);
    }

    /**
     * A command handler's refusal reaches the listener wrapped by the bus. The
     * message of a refused transition is written for the caller and is served;
     * a missing task is a plain 404.
     */
    public function testARefusedTransitionFromAHandlerIsAConflictWithItsReason(): void
    {
        $cause = InvalidTaskTransition::between(VideoTaskStatus::COMPLETED, VideoTaskStatus::CANCELED);
        $event = $this->handle('/api/tasks/x', new HandlerFailedException(new Envelope(new \stdClass()), [$cause]));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame($cause->getMessage(), $this->body($response)['detail']);
        self::assertSame([], $this->logger->records);
    }

    public function testATaskNotFoundFromAHandlerIsANotFound(): void
    {
        $event = $this->handle('/api/tasks/x', new HandlerFailedException(new Envelope(new \stdClass()), [TaskNotFound::withId('x')]));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /** A wrapped surprise is still a surprise: generic to the client, detailed in the log. */
    public function testAnUnexpectedWrappedExceptionIsStillAGenericFailure(): void
    {
        $event = $this->handle('/api/tasks', new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('connection to 10.0.0.5 refused')]));

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringNotContainsString('10.0.0.5', (string) $response->getContent());
        self::assertStringContainsString('10.0.0.5', $this->logger->everythingLogged());
    }

    /** Only the API answers problem+json; anything else keeps its own handling. */
    public function testRequestsOutsideTheApiAreLeftAlone(): void
    {
        $event = $this->handle('/videos/final.mp4', new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    private function handle(string $path, \Throwable $throwable): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        new ApiProblemListener($this->logger)($event);

        return $event;
    }

    /** @return array<mixed> */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
