<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Ui\Http\Idempotency\ClaimResult;
use App\Ui\Http\Idempotency\IdempotencyStore;
use App\Ui\Http\Idempotency\StoredResponse;

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{fingerprint: string, response: StoredResponse|null, expiresAt: int}> */
    public array $records = [];

    /** @var list<string> */
    public array $released = [];

    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult
    {
        $id = $scope.'/'.$key;
        $record = $this->records[$id] ?? null;

        if (null !== $record && $record['expiresAt'] <= $now->toDateTimeImmutable()->getTimestamp()) {
            unset($this->records[$id]);
            $record = null;
        }

        if (null === $record) {
            $this->records[$id] = [
                'fingerprint' => $fingerprint,
                'response' => null,
                'expiresAt' => $now->toDateTimeImmutable()->getTimestamp() + $ttlSeconds,
            ];

            return ClaimResult::claimed();
        }

        if ($record['fingerprint'] !== $fingerprint) {
            return ClaimResult::mismatch();
        }

        return null === $record['response'] ? ClaimResult::inProgress() : ClaimResult::replay($record['response']);
    }

    public function complete(string $scope, string $key, StoredResponse $response): void
    {
        $this->records[$scope.'/'.$key]['response'] = $response;
    }

    public function release(string $scope, string $key): void
    {
        unset($this->records[$scope.'/'.$key]);
        $this->released[] = $scope.'/'.$key;
    }
}
