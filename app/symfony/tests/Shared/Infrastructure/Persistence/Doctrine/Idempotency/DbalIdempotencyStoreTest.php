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
        $result = $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::CLAIMED, $result->outcome);
        self::assertNotNull($result->token, 'a claim names the attempt that holds it');
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

    /**
     * A claim is released by the response that ends its request, and a request
     * that never ends - killed at max_execution_time, out of memory, the
     * container replaced under it - releases nothing. Left there, the row stood
     * until its TTL, a day by default, and the client that followed the
     * protocol and repeated its request was told "still running" for the whole
     * of that day. No request runs anything like the grace, so a claim that old
     * with nothing stored is not a request still running: the retry takes it.
     */
    public function testAClaimNobodyAnsweredIsTakenOverOnceTheGraceHasPassed(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $afterGrace, self::TTL)->outcome);
        self::assertSame(1, $this->rowCount(), 'the abandoned claim is taken over, not joined');
    }

    /** Inside the grace the request may well still be running. */
    public function testAClaimStillInsideTheGraceIsInProgress(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        $justBefore = $this->now->minusSeconds(-(DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS - 1));

        self::assertSame(ClaimOutcome::IN_PROGRESS, $this->store->claim('anonymous', 'k-1', 'fp', $justBefore, self::TTL)->outcome);
    }

    /** The take-over is a claim of its own: it expires from its moment, not the dead one's. */
    public function testTheClaimThatTookOverExpiresFromItsOwnMoment(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);
        $retry = $this->claimToken('anonymous', 'k-1', 'fp', $afterGrace);
        $this->store->complete('anonymous', 'k-1', $retry, new StoredResponse(201, 'application/json', '{}'));

        // Past the first claim's TTL, inside the second's.
        $later = $afterGrace->minusSeconds(-(self::TTL - 1));

        self::assertSame(ClaimOutcome::REPLAY, $this->store->claim('anonymous', 'k-1', 'fp', $later, self::TTL)->outcome);
    }

    /** And its grace runs from its moment too: the next retry inside it is told "still running". */
    public function testATakeOverRestartsTheGrace(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);
        $this->claimToken('anonymous', 'k-1', 'fp', $afterGrace);

        $aMomentLater = $afterGrace->minusSeconds(-1);

        self::assertSame(ClaimOutcome::IN_PROGRESS, $this->store->claim('anonymous', 'k-1', 'fp', $aMomentLater, self::TTL)->outcome);
    }

    /** A retry that died in its turn is taken over in its turn. */
    public function testAClaimTakenOverAndAbandonedAgainIsTakenOverAgain(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);
        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);
        $this->claimToken('anonymous', 'k-1', 'fp', $afterGrace);

        $afterTwoGraces = $afterGrace->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $afterTwoGraces, self::TTL)->outcome);
        self::assertSame(1, $this->rowCount());
    }

    /**
     * The request that abandoned the claim may not be dead - blocked in a
     * system call, which max_execution_time does not count - and when it comes
     * back its writes must not touch the claim the retry took: its answer is
     * not stored, so the retry's client is never replayed the answer to a
     * request it did not make, and its release frees nothing, so the retry's
     * key is not handed to a third request mid-flight. The retry's own writes
     * land as they should.
     */
    public function testTheAttemptThatWasTakenOverCanNeitherAnswerNorRelease(): void
    {
        $abandoned = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);
        $retry = $this->claimToken('anonymous', 'k-1', 'fp', $afterGrace);

        self::assertFalse($this->store->complete('anonymous', 'k-1', $abandoned, new StoredResponse(201, 'application/json', '{"task_id":"late"}')));
        self::assertSame(ClaimOutcome::IN_PROGRESS, $this->store->claim('anonymous', 'k-1', 'fp', $afterGrace, self::TTL)->outcome, 'nothing was stored: the retry is still on it');

        $this->store->release('anonymous', 'k-1', $abandoned);
        self::assertSame(1, $this->rowCount(), 'the retry keeps its claim');

        self::assertTrue($this->store->complete('anonymous', 'k-1', $retry, new StoredResponse(201, 'application/json', '{"task_id":"retry"}')));
        $replay = $this->store->claim('anonymous', 'k-1', 'fp', $afterGrace, self::TTL);
        self::assertSame(ClaimOutcome::REPLAY, $replay->outcome);
        self::assertSame('{"task_id":"retry"}', $replay->response?->body);
    }

    /**
     * begin() opens the unit of work the request's changes belong to, and
     * complete() is its last write: with the claim still this attempt's,
     * everything commits together.
     */
    public function testTheUnitOfWorkCommitsWithTheAnswer(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->begin('anonymous', 'k-1', $token);
        // What the request wrote meanwhile: another row will do.
        $this->store->claim('anonymous', 'work', 'fp', $this->now, self::TTL);

        self::assertTrue($this->store->complete('anonymous', 'k-1', $token, new StoredResponse(201, 'application/json', '{}')));

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(2, $this->rowCount(), 'the work committed with the answer');
    }

    /**
     * With the key taken over meanwhile, nothing commits: the retry's client
     * must never be replayed an answer to somebody else's request, and no task
     * may exist that nobody was told about. The retry takes the key over on
     * its own connection in real life; here, on the same one, its update is
     * part of what rolls back, which changes nothing about what is checked.
     */
    public function testTheUnitOfWorkOfAnAttemptThatWasTakenOverIsRolledBack(): void
    {
        $abandoned = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->begin('anonymous', 'k-1', $abandoned);
        $this->store->claim('anonymous', 'work', 'fp', $this->now, self::TTL);
        $this->claimToken('anonymous', 'k-1', 'fp', $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS));

        self::assertFalse($this->store->complete('anonymous', 'k-1', $abandoned, new StoredResponse(201, 'application/json', '{}')));

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->rowCount(), 'the work of the attempt that lost its key is gone');
    }

    /** A release discards the unit of work along with the claim. */
    public function testAReleaseDiscardsTheUnitOfWork(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->begin('anonymous', 'k-1', $token);
        $this->store->claim('anonymous', 'work', 'fp', $this->now, self::TTL);

        $this->store->release('anonymous', 'k-1', $token);

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(0, $this->rowCount());
    }

    /** A key names one request, dead or alive: another body under it is still a mismatch. */
    public function testAnAbandonedClaimStillRefusesAnotherRequest(): void
    {
        $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        $afterGrace = $this->now->minusSeconds(-DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS);

        self::assertSame(ClaimOutcome::MISMATCH, $this->store->claim('anonymous', 'k-1', 'other', $afterGrace, self::TTL)->outcome);
    }

    public function testAnAnsweredKeyReplaysItsAnswer(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        self::assertTrue($this->store->complete('anonymous', 'k-1', $token, new StoredResponse(201, 'application/json', '{"task_id":"x"}')));

        $result = $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL);

        self::assertSame(ClaimOutcome::REPLAY, $result->outcome);
        self::assertInstanceOf(StoredResponse::class, $result->response);
        self::assertSame(201, $result->response->status);
        self::assertSame('application/json', $result->response->contentType);
        self::assertSame('{"task_id":"x"}', $result->response->body);
    }

    public function testTheSameKeyWithADifferentFingerprintIsAMismatch(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->complete('anonymous', 'k-1', $token, new StoredResponse(201, 'application/json', '{}'));

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
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->release('anonymous', 'k-1', $token);

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $this->now, self::TTL)->outcome);
    }

    public function testAnExpiredRecordIsGoneAndItsKeyIsFree(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->complete('anonymous', 'k-1', $token, new StoredResponse(201, 'application/json', '{}'));

        $later = $this->now->minusSeconds(-(self::TTL + 1));

        self::assertSame(ClaimOutcome::CLAIMED, $this->store->claim('anonymous', 'k-1', 'fp', $later, self::TTL)->outcome);
    }

    /** Right up to the deadline the record still answers. */
    public function testARecordThatHasNotExpiredYetStillReplays(): void
    {
        $token = $this->claimToken('anonymous', 'k-1', 'fp', $this->now);
        $this->store->complete('anonymous', 'k-1', $token, new StoredResponse(201, 'application/json', '{}'));

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

    /** Claims, and hands back the token the claim carries. */
    private function claimToken(string $scope, string $key, string $fingerprint, DateTimeValue $now): string
    {
        $result = $this->store->claim($scope, $key, $fingerprint, $now, self::TTL);
        self::assertSame(ClaimOutcome::CLAIMED, $result->outcome);

        $token = $result->token;
        self::assertNotNull($token);

        return $token;
    }

    private function rowCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM '.DbalIdempotencyStore::TABLE);
    }
}
