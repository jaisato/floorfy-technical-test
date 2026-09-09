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
     *
     * A claim names the attempt that holds it with a token, and the two writes
     * that end a request hand the token back: (scope, key) says which record,
     * not that the record is still this attempt's. A request that outlives the
     * store's grace has its claim taken over by the client's retry, and without
     * the token its late answer was stored over the retry's - and replayed to
     * that client as the answer to a request it never made - while its release
     * deleted a claim that was being worked on.
     */
    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult;

    /**
     * Opens the unit of work the claimed request's changes belong to.
     *
     * Its task, the message that queues the task and, last of all, the stored
     * response commit together through complete(), and only while the claim is
     * still this attempt's; release() discards them. A request that outlived
     * the grace and lost its key to a retry therefore creates nothing: what it
     * wrote is rolled back with the answer it could not store, and the client
     * only ever knows the retry's task.
     */
    public function begin(string $scope, string $key, string $token): void;

    /**
     * Records the response of a claimed key so later identical requests are
     * answered with it, and commits the unit of work begin() opened - both
     * only while the claim is still this attempt's. Answers false, having
     * stored nothing and rolled that work back, when the key changed hands in
     * the meantime.
     */
    public function complete(string $scope, string $key, string $token, StoredResponse $response): bool;

    /**
     * Gives the key back and discards the unit of work: the request failed on
     * our side, and the client's retry should run for real. Only this
     * attempt's claim: one that changed hands belongs to the retry that took
     * it, and is left alone.
     */
    public function release(string $scope, string $key, string $token): void;
}
