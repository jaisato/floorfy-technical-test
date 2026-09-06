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

    public function __construct(private Connection $db)
    {
    }

    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult
    {
        $this->purgeExpired($now);

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

            return ClaimResult::claimed();
        } catch (UniqueConstraintViolationException) {
            // Somebody - most likely this same client, a moment ago - holds the key.
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
}
