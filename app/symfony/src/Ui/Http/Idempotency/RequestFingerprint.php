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
            // Objects as objects, not as associative arrays. Decoded with
            // `true`, a JSON object whose keys happen to be "0", "1", … is a
            // PHP list and indistinguishable from a JSON array, so
            // `{"images":{"0":{…}}}` and `{"images":[{…}]}` fingerprinted the
            // same. The first fails list validation and the second does not:
            // one key could replay the stored 400 for the corrected request,
            // or the stored 201 for the malformed one, where the two bodies
            // differ and the answer is a 409 mismatch.
            $decoded = json_decode($body, false, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }

        if (!$decoded instanceof \stdClass && !\is_array($decoded)) {
            return $body;
        }

        return json_encode(self::sortKeys($decoded), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * The same value with every object's members in a fixed order, so that key
     * order is not part of the fingerprint and the shape of each container
     * still is. A list keeps its order: that one *is* part of the request.
     *
     * Sorted as strings, which is what a JSON key is - `get_object_vars()`
     * hands back "0" as the integer 0, and a default comparison would then be
     * mixing numbers and strings for an order nothing else depends on.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            ksort($members, \SORT_STRING);

            $sorted = new \stdClass();

            foreach ($members as $key => $item) {
                $sorted->{$key} = self::sortKeys($item);
            }

            return $sorted;
        }

        if (\is_array($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        return $value;
    }
}
