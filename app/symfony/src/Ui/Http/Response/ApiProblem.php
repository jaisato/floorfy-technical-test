<?php

declare(strict_types=1);

namespace App\Ui\Http\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error responses in the shape RFC 9457 (formerly RFC 7807) describes.
 *
 * One contract for the whole API, whether the error comes from a controller, the
 * router or an unhandled exception, so a client has one thing to parse.
 *
 * "type" stays about:blank - there is no error taxonomy to point at - and the
 * status line carries the meaning. "detail" is written for the caller and never
 * carries the message of an unexpected exception: those say things like which
 * host a connection was refused by.
 */
final class ApiProblem
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param array<string, mixed>  $extensions extra members, e.g. the violations of a rejected body
     * @param array<string, string> $headers
     */
    public static function response(int $status, string $detail, array $extensions = [], array $headers = []): JsonResponse
    {
        $problem = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ] + $extensions;

        return new JsonResponse($problem, $status, $headers + ['Content-Type' => self::CONTENT_TYPE]);
    }
}
