<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

use App\Shared\Domain\ValueObject\DateTimeValue;

/**
 * Remembers, per caller (scope) and Idempotency-Key, the fingerprint of the
 * request and the response it got, for a limited time.
 */
interface IdempotencyStore
{
    /**
     * Atomically takes the key for this request, or reports what already holds
     * it. A record that expired before $now does not count.
     */
    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult;

    /**
     * Records the response of a claimed key so later identical requests are
     * answered with it.
     */
    public function complete(string $scope, string $key, StoredResponse $response): void;

    /**
     * Gives the key back: the request failed on our side, and the client's
     * retry should run for real.
     */
    public function release(string $scope, string $key): void;
}
