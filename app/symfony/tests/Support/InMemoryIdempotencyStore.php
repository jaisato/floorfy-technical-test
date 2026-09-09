<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Ui\Http\Idempotency\ClaimResult;
use App\Ui\Http\Idempotency\IdempotencyStore;
use App\Ui\Http\Idempotency\StoredResponse;

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{fingerprint: string, token: string, response: StoredResponse|null, expiresAt: int}> */
    public array $records = [];

    /** @var list<string> */
    public array $released = [];

    private int $tokens = 0;

    public function claim(string $scope, string $key, string $fingerprint, DateTimeValue $now, int $ttlSeconds): ClaimResult
    {
        $id = $scope.'/'.$key;
        $record = $this->records[$id] ?? null;

        if (null !== $record && $record['expiresAt'] <= $now->toDateTimeImmutable()->getTimestamp()) {
            unset($this->records[$id]);
            $record = null;
        }

        if (null === $record) {
            $token = 'token-'.++$this->tokens;
            $this->records[$id] = [
                'fingerprint' => $fingerprint,
                'token' => $token,
                'response' => null,
                'expiresAt' => $now->toDateTimeImmutable()->getTimestamp() + $ttlSeconds,
            ];

            return ClaimResult::claimed($token);
        }

        if ($record['fingerprint'] !== $fingerprint) {
            return ClaimResult::mismatch();
        }

        return null === $record['response'] ? ClaimResult::inProgress() : ClaimResult::replay($record['response']);
    }

    public function complete(string $scope, string $key, string $token, StoredResponse $response): bool
    {
        $id = $scope.'/'.$key;

        if (($this->records[$id]['token'] ?? null) !== $token) {
            return false;
        }

        $this->records[$id]['response'] = $response;

        return true;
    }

    public function release(string $scope, string $key, string $token): void
    {
        $id = $scope.'/'.$key;

        if (($this->records[$id]['token'] ?? null) !== $token) {
            return;
        }

        unset($this->records[$id]);
        $this->released[] = $id;
    }

    /**
     * Hands the claim to another attempt, as the real store does with a claim
     * that stood unanswered past its grace: the attempt that held it keeps its
     * token, which now names nothing.
     */
    public function takeOver(string $scope, string $key): void
    {
        $this->records[$scope.'/'.$key]['token'] = 'token-of-the-retry';
    }
}
