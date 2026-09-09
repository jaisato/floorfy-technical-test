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
     * so a claim this old with nothing stored is not a request still running.
     *
     * What it may be is a request that did its work and died in the moment
     * between the commit and the storing of its answer; the retry then does
     * the work again. That is the exposure every idempotency scheme has past
     * its lock, and the alternative was a day of "still running" for a task
     * that, far more often, was never created at all.
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

        if ($this->insert($scope, $key, $fingerprint, $now, $ttlSeconds)) {
            return ClaimResult::claimed();
        }

        // Somebody - most likely this same client, a moment ago - holds the key.
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
            // No response yet: the request is running, or it died without
            // answering and nobody will ever write one. The age tells them
            // apart, and a dead claim is taken over - by this request, or by
            // whichever concurrent retry of the same lost request gets its
            // INSERT in first, exactly as a fresh claim is decided.
            if ($this->releaseAbandoned($scope, $key, $now) && $this->insert($scope, $key, $fingerprint, $now, $ttlSeconds)) {
                return ClaimResult::claimed();
            }

            return ClaimResult::inProgress();
        }

        return ClaimResult::replay(new StoredResponse(
            (int) $status,
            \is_string($row['response_content_type']) ? $row['response_content_type'] : 'application/json',
            \is_string($row['response_body']) ? $row['response_body'] : '',
        ));
    }

    public function complete(string $scope, string $key, StoredResponse $response): void
    {
        $this->db->update(self::TABLE, [
            'response_status' => $response->status,
            'response_content_type' => $response->contentType,
            'response_body' => $response->body,
        ], ['scope' => $scope, 'idempotency_key' => $key]);
    }

    public function release(string $scope, string $key): void
    {
        $this->db->delete(self::TABLE, ['scope' => $scope, 'idempotency_key' => $key]);
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
     *
     * Impure to the analyser: the same arguments can be refused once and
     * accepted a moment later, after the abandoned row in the way is gone.
     *
     * @phpstan-impure
     */
    private function insert(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): bool
    {
        try {
            $this->db->insert(self::TABLE, [
                'scope' => $scope,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => $now->toDateTimeImmutable(),
                'expires_at' => $now->minusSeconds(-$ttlSeconds)->toDateTimeImmutable(),
            ], [
                'scope' => ParameterType::STRING,
                'idempotency_key' => ParameterType::STRING,
                'fingerprint' => ParameterType::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Removes a claim that has stood without a response for longer than the
     * grace, and says whether it did.
     *
     * Conditional on the row still being that claim - no response, and older
     * than the grace - so a request that answers in the meantime keeps its
     * record, and two retries of the same dead request cannot both believe
     * they cleared the way: one DELETE changes a row, and the INSERT that
     * follows is arbitrated by the primary key like every other claim.
     */
    private function releaseAbandoned(string $scope, string $key, DateTimeValue $now): bool
    {
        return 1 === $this->db->executeStatement(
            \sprintf('DELETE FROM %s WHERE scope = :scope AND idempotency_key = :key AND response_status IS NULL AND created_at <= :stale', self::TABLE),
            [
                'scope' => $scope,
                'key' => $key,
                'stale' => $now->minusSeconds($this->inProgressGraceSeconds)->toDateTimeImmutable(),
            ],
            [
                'scope' => ParameterType::STRING,
                'key' => ParameterType::STRING,
                'stale' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
