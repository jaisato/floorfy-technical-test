<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Idempotency;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Ui\Http\Idempotency\ClaimResult;
use App\Ui\Http\Idempotency\IdempotencyStore;
use App\Ui\Http\Idempotency\StoredResponse;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Idempotency records in the idempotency_keys table.
 *
 * The primary key (scope, idempotency_key) is what makes a claim atomic: two
 * concurrent requests with the same key race on the INSERT and exactly one
 * wins, on MySQL and SQLite alike. There is no read-then-write anywhere.
 *
 * Each claim also writes a token naming that attempt, and the writes that end
 * it carry the token back. (scope, key) names the row; it does not say that
 * the row is still the one that was claimed. A claim that stood unanswered
 * past the grace is taken over by the client's retry, and the request that
 * abandoned it may not be dead - see IN_PROGRESS_GRACE_SECONDS - so without
 * the token its late complete() stored its answer over the retry's, to be
 * replayed to that client as the answer to a request it never made, and its
 * release() deleted a claim that was being worked on.
 */
final readonly class DbalIdempotencyStore implements IdempotencyStore
{
    public const string TABLE = 'idempotency_keys';

    /**
     * How long a claim may stand with no response before it is read as the
     * leftover of a request that never answered, and taken over.
     *
     * A claim is released by the response that ends its request, and a request
     * that never ends - stopped by php-fpm at max_execution_time, out of
     * memory, the container replaced under it - releases nothing. Left there,
     * the row stood until its TTL, a day by default, and the client that
     * followed the protocol and repeated its request was told 409 "still
     * running" for the whole of that day, for a task that was never created.
     * Nothing here runs anything like this long: php-fpm stops a request at
     * max_execution_time (60 s in the image) and nginx stops waiting at 30 s,
     * so a claim this old with nothing stored is not a request still answering
     * anybody.
     *
     * Not answering anybody is not the same as dead. max_execution_time counts
     * CPU time on Linux, not a wait, so a request blocked in a system call - a
     * resolver that does not answer, a database that does not - is still there
     * long after nginx gave up on it, and PHP only notices the client has gone
     * when it writes to it, which the controller does at the very end. Such a
     * request comes back to a claim that is no longer its own: its answer is
     * not stored and its release releases nothing, both refused by the token.
     * The task it goes on to create is the one thing the token cannot undo -
     * the client only ever sees the retry's - and it is the exposure every
     * idempotency scheme has past its lock, no different from a request that
     * did its work and died between the commit and the storing of its answer.
     * The alternative was a day of "still running" for a task that, far more
     * often, was never created at all.
     */
    public const int IN_PROGRESS_GRACE_SECONDS = 300;

    public function __construct(
        private Connection $db,
        private int $inProgressGraceSeconds = self::IN_PROGRESS_GRACE_SECONDS,
    ) {
    }

    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult
    {
        $this->purgeExpired($now);
        $token = bin2hex(random_bytes(16));

        if ($this->insert($scope, $key, $fingerprint, $token, $now, $ttlSeconds)) {
            return ClaimResult::claimed($token);
        }

        // Somebody - most likely this same client, a moment ago - holds the key.
        // A claim nobody answered within the grace is taken over: by this
        // request, or by whichever concurrent retry of the same lost request
        // gets its UPDATE in first - one row changes once, so two retries
        // cannot both believe they took it. Under a new token, so the attempt
        // that abandoned it can no longer answer for it or release it, and
        // with the clock restarted: a take-over is a claim of its own, in
        // flight from this moment, and the next retry inside the grace is
        // told "still running" like any other.
        if ($this->takeOver($scope, $key, $fingerprint, $token, $now, $ttlSeconds)) {
            return ClaimResult::claimed($token);
        }

        $row = $this->db->fetchAssociative(
            \sprintf('SELECT fingerprint, response_status, response_content_type, response_body FROM %s WHERE scope = :scope AND idempotency_key = :key', self::TABLE),
            ['scope' => $scope, 'key' => $key],
        );

        if (false === $row) {
            // Released between our failed insert and this read; the honest
            // answer for this instant is that the other request is still on it.
            return ClaimResult::inProgress();
        }

        if ($row['fingerprint'] !== $fingerprint) {
            return ClaimResult::mismatch();
        }

        $status = $row['response_status'];

        if (!\is_int($status) && !(\is_string($status) && 1 === preg_match('/^\d+$/', $status))) {
            // No response yet, and not old enough to take over: the request
            // is running, or a retry took the key a moment ago and is.
            return ClaimResult::inProgress();
        }

        return ClaimResult::replay(new StoredResponse(
            (int) $status,
            \is_string($row['response_content_type']) ? $row['response_content_type'] : 'application/json',
            \is_string($row['response_body']) ? $row['response_body'] : '',
        ));
    }

    public function complete(string $scope, string $key, string $token, StoredResponse $response): bool
    {
        return 1 === (int) $this->db->update(self::TABLE, [
            'response_status' => $response->status,
            'response_content_type' => $response->contentType,
            'response_body' => $response->body,
        ], ['scope' => $scope, 'idempotency_key' => $key, 'claim_token' => $token]);
    }

    public function release(string $scope, string $key, string $token): void
    {
        $this->db->delete(self::TABLE, ['scope' => $scope, 'idempotency_key' => $key, 'claim_token' => $token]);
    }

    /**
     * An expired record is as good as absent. Removing every one of them here,
     * on the indexed column, keeps the table from needing a cron of its own.
     *
     * @return int how many were removed
     */
    public function purgeExpired(DateTimeValue $now): int
    {
        return (int) $this->db->executeStatement(
            \sprintf('DELETE FROM %s WHERE expires_at <= :now', self::TABLE),
            ['now' => $now->toDateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The claim itself: one INSERT, decided by the primary key.
     */
    private function insert(string $scope, string $key, string $fingerprint, string $token, DateTimeValue $now, int $ttlSeconds): bool
    {
        try {
            $this->db->insert(self::TABLE, [
                'scope' => $scope,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'claim_token' => $token,
                'created_at' => $now->toDateTimeImmutable(),
                'expires_at' => $now->minusSeconds(-$ttlSeconds)->toDateTimeImmutable(),
            ], [
                'scope' => ParameterType::STRING,
                'idempotency_key' => ParameterType::STRING,
                'fingerprint' => ParameterType::STRING,
                'claim_token' => ParameterType::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Takes over a claim that has stood without a response for longer than the
     * grace, and says whether it did.
     *
     * Conditional on the row still being that claim - this request's own
     * fingerprint, no response, older than the grace - so a request that
     * answers in the meantime keeps its record, another request under the
     * same key is still a mismatch, and two retries of the same dead request
     * cannot both take it: one UPDATE changes the row, and the other reads
     * the row it left, which is a claim inside its grace.
     */
    private function takeOver(string $scope, string $key, string $fingerprint, string $token, DateTimeValue $now, int $ttlSeconds): bool
    {
        return 1 === $this->db->executeStatement(
            \sprintf(
                'UPDATE %s SET claim_token = :token, created_at = :now, expires_at = :expires'
                .' WHERE scope = :scope AND idempotency_key = :key AND fingerprint = :fingerprint'
                .' AND response_status IS NULL AND created_at <= :stale',
                self::TABLE,
            ),
            [
                'token' => $token,
                'now' => $now->toDateTimeImmutable(),
                'expires' => $now->minusSeconds(-$ttlSeconds)->toDateTimeImmutable(),
                'scope' => $scope,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'stale' => $now->minusSeconds($this->inProgressGraceSeconds)->toDateTimeImmutable(),
            ],
            [
                'token' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'expires' => Types::DATETIME_IMMUTABLE,
                'scope' => ParameterType::STRING,
                'key' => ParameterType::STRING,
                'fingerprint' => ParameterType::STRING,
                'stale' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
