<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

use Symfony\Component\HttpFoundation\Response;

/**
 * What a replay of an idempotent request answers: the status, content type
 * and body of the original response.
 */
final readonly class StoredResponse
{
    public function __construct(
        public int $status,
        public string $contentType,
        public string $body,
    ) {
    }

    public static function fromResponse(Response $response): self
    {
        $content = $response->getContent();

        return new self(
            $response->getStatusCode(),
            (string) $response->headers->get('Content-Type', 'application/json'),
            false === $content ? '' : $content,
        );
    }
}
