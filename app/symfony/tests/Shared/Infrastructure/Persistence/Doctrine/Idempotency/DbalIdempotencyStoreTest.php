<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Persistence\Doctrine\Idempotency;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Infrastructure\Persistence\Doctrine\Entity\IdempotencyKeyEntity;
use App\Shared\Infrastructure\Persistence\Doctrine\Idempotency\DbalIdempotencyStore;
use App\Tests\Support\DatabaseTestCase;
use App\Ui\Http\Idempotency\ClaimOutcome;
use App\Ui\Http\Idempotency\StoredResponse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The store against a real database: the claim is one INSERT, so the table's
 * primary key is what arbitrates a race, and an expired record is gone rather
 * than merely ignored.
 */
final class DbalIdempotencyStoreTest extends DatabaseTestCase
{
    private const int TTL = 3600;

    private DbalIdempotencyStore $store;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DbalIdempotencyStore($this->connection());
        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testAFreeKeyIsClaimed(): void
    {
        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL)->outcome);
    }

    /**
     * The second of two requests racing on the same key: the row is there, no
     * response has been written yet, and the honest answer is "still running".
     */
    public function testAClaimedKeyThatHasNoAnswerYetIsInProgress(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::IN_PROGRESS, $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL)->outcome);
    }

    public function testAnAnsweredKeyReplaysItsAnswer(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->complete('anonymous', 'k-1', new StoredResponse(201, 'application/json', '{"task_id":"x"}'));

        $result = $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::REPLAY, $result->outcome);
        self::assertInstanceOf(StoredResponse::class, $result->response);
        self::assertSame(201, $result->response->status);
        self::assertSame('application/json', $result->response->contentType);
        self::assertSame('{"task_id":"x"}', $result->response->body);
    }

    public function testTheSameKeyWithADifferentFingerprintIsAMismatch(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->complete('anonymous', 'k-1', new StoredResponse(201, 'application/json', '{}'));

        self::assertSame(ClaimOutcome::MISMATCH, $this->store->claim('anonymous', 'k-1', 'other', $this->now, self::TTL)->outcome);
    }

    /** A mismatch outranks "still running": the key names another request. */
    public function testADifferentFingerprintIsAMismatchEvenWhileInFlight(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::MISMATCH, $this->store->claim('anonymous', 'k-1', 'other', $this->now, self::TTL)->outcome);
    }

    /** Two callers must not be able to collide on a key one of them chose. */
    public function testTheSameKeyUnderAnotherScopeIsFree(): void
    {
        $this->store->claim('client-a', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('client-b', 'k-1', 'fp', $this->now, self::TTL)->outcome);
    }

    /**
     * An Idempotency-Key is an opaque token the caller chose, and a scope is a
     * client name. Under the table's utf8mb4_unicode_ci both compared
     * case-insensitively on MySQL: `ABC` and `abc` were one key - a client got
     * somebody else's answer, or a 422 mismatch that makes no sense - and
     * clients configured as `Acme` and `acme` shared one namespace and could
     * replay each other's stored responses. Both columns are utf8mb4_bin, which
     * is what SQLite does for TEXT anyway; the middleware only teaches it the
     * name so the same mapping builds here.
     */
    public function testKeysAndScopesAreComparedByteForByte(): void
    {
        $this->store->claim('anonymous', 'ABC', 'fp', $this->now, self::TTL);
        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'abc', 'fp', $this->now, self::TTL)->outcome);

        $this->store->claim('Acme', 'k-1', 'fp', $this->now, self::TTL);
        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('acme', 'k-1', 'fp', $this->now, self::TTL)->outcome);

        // SQLite compares TEXT byte for byte whatever the mapping says, so the
        // two claims above pass here either way; only MySQL can tell the
        // difference, and only if the mapping asks for it. That is what this
        // last part checks - it is the collation, not the engine, under test.
        $metadata = self::getContainer()->get(EntityManagerInterface::class)
            ->getClassMetadata(IdempotencyKeyEntity::class);

        foreach (['scope', 'idempotencyKey'] as $field) {
            self::assertSame(
                'utf8mb4_bin',
                $metadata->getFieldMapping($field)->options['collation'] ?? null,
                $field,
            );
        }
    }

    public function testAReleasedKeyIsFreeAgain(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->release('anonymous', 'k-1');

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL)->outcome);
    }

    public function testAnExpiredRecordIsGoneAndItsKeyIsFree(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->complete('anonymous', 'k-1', new StoredResponse(201, 'application/json', '{}'));

        $later = $this->now->minusSeconds(-(self::TTL + 1));

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $later, self::TTL)->outcome);
    }

    /** Right up to the deadline the record still answers. */
    public function testARecordThatHasNotExpiredYetStillReplays(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->complete('anonymous', 'k-1', new StoredResponse(201, 'application/json', '{}'));

        $justBefore = $this->now->minusSeconds(-(self::TTL - 1));

        self::assertSame(ClaimOutcome::REPLAY, $this->store->claim('anonymous', 'k-1', 'fp', $justBefore, self::TTL)->outcome);
    }

    /** Nothing else has to sweep the table: every claim clears what expired. */
    public function testExpiredRecordsAreRemovedFromTheTable(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $this->store->claim('anonymous', 'k-2', 'fp', $this->now, self::TTL);

        $removed = $this->store->purgeExpired($this->now->minusSeconds(-(self::TTL + 1)));

        self::assertSame(2, $removed);
        self::assertSame(0, $this->rowCount());
    }

    public function testPurgingLeavesLiveRecordsAlone(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(0, $this->store->purgeExpired($this->now));
        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM '.DbalIdempotencyStore::TABLE);
    }
}
