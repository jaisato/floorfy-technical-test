<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

use Symfony\Component\HttpFoundation\Request;

/**
 * What makes two requests "the same" for an Idempotency-Key: method, path and
 * body. JSON bodies are compared structurally (keys re-ordered), so a client
 * that serialises the same object in a different key order still replays.
 */
final class RequestFingerprint
{
    private function __construct()
    {
    }

    public static function of(Request $request): string
    {
        return hash('sha256', $request->getMethod()."\n".$request->getPathInfo()."\n".self::canonicalBody((string) $request->getContent()));
    }

    private static function canonicalBody(string $body): string
    {
        if ('' === trim($body)) {
            return '';
        }

        try {
            $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }

        if (!\is_array($decoded)) {
            return $body;
        }

        return json_encode(self::sortKeys($decoded), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
