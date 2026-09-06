<?php

declare(strict_types=1);

namespace App\Ui\Http\EventListener;

use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Ui\Http\Response\ApiProblem;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Turns anything that escapes an API controller into the same problem document
 * the controllers themselves return.
 *
 * Without this, a request to a path the router does not know, or with the wrong
 * method, is answered by the framework with an HTML error page - from a JSON
 * API - while a controller's own 404 is JSON. One contract, whatever went wrong.
 *
 * Nothing an unexpected exception says reaches the client: its message is where
 * a driver puts the host it could not reach and a filesystem puts the path it
 * could not read. It goes to the log instead.
 *
 * The exceptions a command handler throws on purpose are the exception to
 * that: TaskNotFound is a 404 and a refused transition a 409, and since their
 * messages are written for the caller (they say which transition, from which
 * status) they are served as the detail. Messenger wraps whatever a handler
 * throws, so the wrapper is unpacked first.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ApiProblemListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = self::unwrap($event->getThrowable());
        $isHttp = $throwable instanceof HttpExceptionInterface;

        $status = match (true) {
            $isHttp => $throwable->getStatusCode(),
            $throwable instanceof TaskNotFound => Response::HTTP_NOT_FOUND,
            $throwable instanceof InvalidTaskTransition => Response::HTTP_CONFLICT,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
        $headers = $isHttp ? $throwable->getHeaders() : [];
        $detail = $throwable instanceof InvalidTaskTransition ? $throwable->getMessage() : self::detailFor($status);

        if ($status >= 500) {
            $this->logger->error('Unhandled exception in the API', [
                'method' => $event->getRequest()->getMethod(),
                'path' => $event->getRequest()->getPathInfo(),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        $event->setResponse(ApiProblem::response($status, $detail, [], self::stringHeaders($headers)));
    }

    /**
     * A synchronous bus reports a handler's exception wrapped in
     * HandlerFailedException; the one the handler threw is what matters here.
     */
    private static function unwrap(\Throwable $throwable): \Throwable
    {
        while ($throwable instanceof HandlerFailedException) {
            $wrapped = $throwable->getWrappedExceptions();
            $first = reset($wrapped);

            if (!$first instanceof \Throwable) {
                break;
            }

            $throwable = $first;
        }

        return $throwable;
    }

    private static function detailFor(int $status): string
    {
        return match ($status) {
            Response::HTTP_NOT_FOUND => 'No hay ningún recurso en esa dirección.',
            Response::HTTP_METHOD_NOT_ALLOWED => 'Ese método no está permitido para este recurso.',
            Response::HTTP_BAD_REQUEST => 'La petición no es válida.',
            Response::HTTP_CONFLICT => 'El estado actual del recurso no permite esa operación.',
            Response::HTTP_INTERNAL_SERVER_ERROR => 'La petición no se pudo procesar.',
            default => 'La petición no se pudo completar.',
        };
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $kept = [];

        foreach ($headers as $name => $value) {
            if (\is_string($value)) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }
}
