<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\ORM\TransactionRequiredException;
use PHPUnit\Framework\Attributes\Group;

final class DoctrineVideoTaskRepositoryTest extends DatabaseTestCase
{
    private VideoTaskRepository $repository;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(VideoTaskRepository::class);
        self::assertInstanceOf(VideoTaskRepository::class, $repository);
        $this->repository = $repository;

        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testATaskSurvivesARoundTrip(): void
    {
        $task = VideoTask::create(['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]], $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame($task->id()->value, $loaded->id()->value);
        self::assertSame($task->payload(), $loaded->payload());
        self::assertSame(VideoTaskStatus::PENDING, $loaded->status());
        self::assertTrue($this->now->equals($loaded->createdAt()));
    }

    public function testAnUnknownIdReadsBackAsNothing(): void
    {
        self::assertNull($this->repository->get(UuidValue::new()));
    }

    public function testUpdatesAreWrittenBack(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost/videos/final.mp4', $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::COMPLETED, $loaded->status());
        self::assertSame('http://localhost/videos/final.mp4', $loaded->finalVideoUrl());
    }

    /**
     * The point of the conditional UPDATE: read-then-write would let two
     * workers handed the same message both see "pending" and both start.
     */
    public function testOnlyTheFirstClaimSucceeds(): void
    {
        $task = $this->storedTask();

        self::assertNotNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
        self::assertNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    public function testClaimingWritesTheProcessingStatus(): void
    {
        $task = $this->storedTask();

        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::PROCESSING, $loaded->status());
    }

    /**
     * The statement goes straight to the connection, so an instance the unit of
     * work is already holding would keep reporting the old status and write it
     * back on the next flush.
     */
    public function testAnAlreadyLoadedTaskSeesTheClaim(): void
    {
        $task = $this->storedTask();
        $this->repository->get($task->id());

        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::PROCESSING, $loaded->status());
    }

    public function testAnUnknownTaskCannotBeClaimed(): void
    {
        self::assertNull($this->repository->claimForProcessing(UuidValue::new(), $this->now, $this->staleBefore()));
    }

    public function testACompletedTaskCannotBeClaimed(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost/videos/final.mp4', $this->now);
        $this->repository->save($task);

        self::assertNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    /** So that `messenger:failed:retry` is not a no-op. */
    public function testAFailedTaskCanBeClaimedAgain(): void
    {
        $task = $this->storedTask();
        $task->markFailed('boom', $this->now);
        $this->repository->save($task);

        self::assertNotNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    /**
     * A run starting carries none of the previous one's outcome, whichever way
     * in it came: the /retry endpoint goes through VideoTask::retry(), and
     * `messenger:failed:retry` replays the original message straight into this
     * claim. prunedAt is the one that does damage left behind - it says the
     * task's files were reclaimed, and it is exactly what excludes a row from
     * the retention sweep, so a replayed task kept telling clients its video
     * was gone while rendering one nothing would ever clean up.
     */
    public function testClaimingAPrunedFailedTaskStartsItClean(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markFailed('boom', $this->now);
        $task->markPruned($this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        self::assertNotNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::PROCESSING, $loaded->status());
        self::assertNull($loaded->prunedAt(), 'a task rendering a new video is not one whose files were reclaimed');
        self::assertNull($loaded->errorMessage());
        self::assertNull($loaded->finalVideoUrl());
    }

    /** The sweep must be able to find it again once the new run settles. */
    public function testATaskCompletedAfterAReplayIsPrunableAgain(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markFailed('boom', $this->now);
        $task->markPruned($this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());
        $this->entityManager->clear();

        $reclaimed = $this->repository->get($task->id());
        self::assertNotNull($reclaimed);
        $reclaimed->markCompleted('http://localhost/videos/again.mp4', $this->now);
        $this->repository->save($reclaimed);
        $this->entityManager->clear();

        // A second past the last touch, so the cutoff is behind it.
        $cutoff = $this->now->minusSeconds(-1);

        self::assertContains(
            $task->id()->value,
            array_map(static fn (VideoTask $t): string => $t->id()->value, $this->repository->listPrunable($cutoff, 10)),
        );
    }

    /** A worker killed mid-task must not strand it in "processing" for ever. */
    public function testAClaimOlderThanTheLeaseCanBeTakenOver(): void
    {
        $task = $this->storedTask();
        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        // Ten minutes into a one-hour lease: the task still belongs to whoever
        // took it.
        $soon = $this->now->minusSeconds(-600);
        self::assertNull(
            $this->repository->claimForProcessing($task->id(), $soon, $soon->minusSeconds(3600)),
        );

        // Two hours in, with the same lease: the claim is abandoned.
        $muchLater = $this->now->minusSeconds(-7200);
        self::assertNotNull(
            $this->repository->claimForProcessing($task->id(), $muchLater, $muchLater->minusSeconds(3600)),
        );
    }

    public function testReleasingMakesTheTaskClaimableAgain(): void
    {
        $task = $this->storedTask();
        $claim = $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        self::assertNotNull($claim);
        self::assertNotNull($this->repository->release($task->id(), $claim, $this->now));
        self::assertNotNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    public function testReleasingATaskNobodyClaimedChangesNothing(): void
    {
        $task = $this->storedTask();

        self::assertNull($this->repository->release($task->id(), VideoTask::FIRST_RUN, $this->now));
    }

    /**
     * The claim the attempt is holding, not just "somebody is processing this".
     *
     * Cancel a task while its worker is inside ffmpeg and retry it, and the
     * replacement worker puts the row back to processing: with a status-only
     * condition the old attempt's next renewal matched again, and it went on
     * to release or complete a run that was not its own while both wrote the
     * same staging files.
     */
    public function testAnAttemptThatLostItsClaimCanNeitherRenewNorReleaseNorComplete(): void
    {
        $task = $this->storedTask();
        $first = $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());
        self::assertNotNull($first);

        // Canceled, retried and claimed again while the first attempt is busy.
        self::assertNotNull($this->repository->cancel($task->id(), $this->now));
        $task->cancel($this->now);
        $task->retry($this->now);
        $this->repository->save($task);
        $second = $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'a claim is a number, and each one is its own');

        self::assertFalse($this->repository->renewLease($task->id(), $first, $this->now));
        self::assertNull($this->repository->release($task->id(), $first, $this->now));
        self::assertNull($this->repository->complete($task->id(), '/videos/final.mp4', $first, $this->now));

        self::assertSame(
            VideoTaskStatus::PROCESSING,
            $this->repository->currentStatus($task->id()),
            'and the run that does hold the claim is untouched',
        );

        self::assertTrue($this->repository->renewLease($task->id(), $second, $this->now));
        self::assertNotNull($this->repository->complete($task->id(), '/videos/final.mp4', $second, $this->now));
    }

    /**
     * Two renewals on one clock, which is what a part that finishes inside a
     * second - or is reused whole from an earlier run - produces.
     *
     * `updated_at` is a DATETIME, so the second renewal writes the value the
     * column already holds. MySQL's affected-row count is rows *changed*, not
     * rows matched, so it reported zero and the attempt read that as having
     * lost its claim: a healthy run abandoned mid-way, and the message
     * acknowledged as if the task had been canceled. SQLite counts matched
     * rows, which is why the test profile never saw it and the MySQL job did.
     */
    public function testRenewingTwiceOnTheSameClockKeepsTheClaim(): void
    {
        $task = $this->storedTask();
        $claim = $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        self::assertNotNull($claim);
        self::assertTrue($this->repository->renewLease($task->id(), $claim, $this->now));
        self::assertTrue(
            $this->repository->renewLease($task->id(), $claim, $this->now),
            'the deadline is already where this would put it; the claim is still held',
        );
    }

    public function testCancelingWritesTheStatusOverAPendingOrProcessingTask(): void
    {
        $pending = $this->storedTask();
        $processing = $this->storedTask();
        $this->repository->claimForProcessing($processing->id(), $this->now, $this->staleBefore());

        self::assertNotNull($this->repository->cancel($pending->id(), $this->now));
        self::assertNotNull($this->repository->cancel($processing->id(), $this->now));
        $this->entityManager->clear();

        self::assertSame(VideoTaskStatus::CANCELED, $this->repository->get($pending->id())?->status());
        self::assertSame(VideoTaskStatus::CANCELED, $this->repository->get($processing->id())?->status());
    }

    /**
     * The database arbitrates: a task that completed a moment ago is not
     * overwritten, whatever the caller read before.
     */
    public function testCancelingASettledTaskChangesNothing(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost/videos/final.mp4', $this->now);
        $this->repository->save($task);

        self::assertNull($this->repository->cancel($task->id(), $this->now));
        self::assertNull($this->repository->cancel(UuidValue::new(), $this->now));

        $canceled = $this->storedTask();
        self::assertNotNull($this->repository->cancel($canceled->id(), $this->now));
        self::assertNull($this->repository->cancel($canceled->id(), $this->now), 'a second cancellation finds nothing to cancel');
    }

    public function testACanceledTaskCannotBeClaimed(): void
    {
        $task = $this->storedTask();
        $this->repository->cancel($task->id(), $this->now);

        self::assertNull($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    /**
     * The worker asks between parts whether it should go on; a copy loaded at
     * the start of the attempt would still say "processing".
     */
    public function testTheCurrentStatusIsReadFromTheRowNotFromTheLoadedCopy(): void
    {
        $task = $this->storedTask();
        $this->repository->get($task->id());
        $this->connection()->executeStatement('UPDATE video_tasks SET status = ? WHERE id = ?', ['canceled', $task->id()->value]);

        self::assertSame(VideoTaskStatus::CANCELED, $this->repository->currentStatus($task->id()));
        self::assertNull($this->repository->currentStatus(UuidValue::new()));
    }

    /**
     * The mark is one column per task and a task settles once per run, so the
     * recovery sweep - which looks for settled tasks whose callback never went
     * out - can only see the second run's lost notification once the first
     * run's mark is gone.
     */
    public function testTheCallbackMarkCanBeWrittenAndTakenBackOff(): void
    {
        $task = $this->storedTask();

        self::assertTrue($this->repository->markCallbackNotified($task->id(), $task->status()->value, $task->runGeneration(), $this->now));
        self::assertNotNull($this->connection()->fetchOne('SELECT callback_notified_at FROM video_tasks WHERE id = ?', [$task->id()->value]));

        $this->repository->clearCallbackNotification($task->id());
        self::assertNull($this->connection()->fetchOne('SELECT callback_notified_at FROM video_tasks WHERE id = ?', [$task->id()->value]));
    }

    /**
     * The listing the sweep reads, and the three states that take a task out
     * of it. The DQL is where the clause has to be, not only the claim: the
     * sweep lists first and claims what it listed.
     */
    public function testTheSweepsListingSkipsCallbacksDeliveredAndCallbacksGivenUpOn(): void
    {
        $owed = $this->storedTaskAwaitingCallback();
        $delivered = $this->storedTaskAwaitingCallback();
        $abandoned = $this->storedTaskAwaitingCallback();

        $this->repository->markCallbackNotified($delivered->id(), $delivered->status()->value, $delivered->runGeneration(), $this->now);
        $this->repository->markCallbackAbandoned($abandoned->id(), $abandoned->status()->value, $abandoned->runGeneration(), $this->now);
        $this->entityManager->clear();

        $listed = array_map(
            static fn (VideoTask $task): string => $task->id()->value,
            $this->repository->listAwaitingCallback($this->now->minusSeconds(-60), 10),
        );

        self::assertSame([$owed->id()->value], $listed);
    }

    /**
     * A retry between the listing and the claim takes the notification back.
     *
     * The three callback columns alone did not say so: retrying clears all
     * three, so the claim landed on a task that was pending again, and the
     * sweep published the *listed* object's terminal status while the handler
     * built the body from the run now under way - the client told the task had
     * failed as it started over. The claim repeats every clause of the
     * listing, which is what makes the retry win it.
     */
    public function testARetryBetweenTheListingAndTheClaimTakesTheNotificationBack(): void
    {
        // Failed rather than completed: retrying is what a failed task does.
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markProcessing($this->now);
        $task->markFailed('boom', $this->now);
        $this->repository->save($task);
        $cutoff = $this->now->minusSeconds(-60);

        // Listed as owed, and it is.
        self::assertCount(1, $this->repository->listAwaitingCallback($cutoff, 10));

        // Then the retry lands: pending again, and its callback state cleared.
        $this->repository->clearCallbackNotification($task->id());
        $task->retry($cutoff);
        $this->repository->save($task);

        self::assertFalse(
            $this->repository->claimCallbackNotification($task->id(), $task->runGeneration(), $cutoff, $this->now),
            'the run the notification was listed for is not the run there now',
        );
        self::assertNull($this->connection()->fetchOne('SELECT callback_attempted_at FROM video_tasks WHERE id = ?', [$task->id()->value]));
    }

    /** A task that asked for no callback is nothing to claim either. */
    public function testATaskWithoutACallbackUrlIsNotClaimable(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);
        $this->repository->save($task);

        self::assertFalse($this->repository->claimCallbackNotification($task->id(), $task->runGeneration(), $this->now->minusSeconds(-60), $this->now));
    }

    /**
     * The verdict on a notification nothing can deliver, and the same round
     * trip: written under the run's fence, and taken back off when the task is
     * queued again - the URL is re-checked on the next delivery and the signing
     * secret may have been configured since.
     */
    public function testTheGiveUpMarkCanBeWrittenAndTakenBackOff(): void
    {
        $task = $this->storedTask();

        self::assertTrue($this->repository->markCallbackAbandoned($task->id(), $task->status()->value, $task->runGeneration(), $this->now));
        self::assertNotNull($this->connection()->fetchOne('SELECT callback_abandoned_at FROM video_tasks WHERE id = ?', [$task->id()->value]));

        // And while it stands, the sweep cannot take the notification.
        self::assertFalse($this->repository->claimCallbackNotification($task->id(), $task->runGeneration(), $this->now, $this->now));

        $this->repository->clearCallbackNotification($task->id());
        self::assertNull($this->connection()->fetchOne('SELECT callback_abandoned_at FROM video_tasks WHERE id = ?', [$task->id()->value]));
    }

    /**
     * Two runs of one task can end the same way, so the status does not say
     * which of them a delivery was announcing. Run A ends failed and its
     * delivery is slow; a retry runs B, which fails too; A's mark was then
     * accepted for B, and when B's own notification was lost the sweep found
     * callback_notified_at set and never offered the task again.
     *
     * The instant the run settled cannot tell them apart either: `updated_at`
     * is a MySQL DATETIME, and cancel, retry and cancel again with no worker in
     * between puts both runs in the same second - which is what both runs here
     * do, at one fixed clock. The generation is what names the run.
     */
    public function testAMarkFromAnEarlierRunIsRefusedEvenWhenBothEndedInTheSameSecond(): void
    {
        $task = $this->storedTask();

        $runA = $this->repository->cancel($task->id(), $this->now);
        self::assertNotNull($runA);

        // Retried and canceled again, at the very same instant.
        $task->cancel($this->now);
        $task->retry($this->now);
        $this->repository->save($task);
        $runB = $this->repository->cancel($task->id(), $this->now);

        self::assertNotNull($runB);
        self::assertNotSame($runA, $runB, 'two runs, one second');

        self::assertFalse(
            $this->repository->markCallbackNotified($task->id(), VideoTaskStatus::CANCELED->value, $runA, $this->now),
            "the first run's late delivery does not answer for the second",
        );
        self::assertNull($this->connection()->fetchOne('SELECT callback_notified_at FROM video_tasks WHERE id = ?', [$task->id()->value]));

        self::assertTrue(
            $this->repository->markCallbackNotified($task->id(), VideoTaskStatus::CANCELED->value, $runB, $this->now),
            'the run that is actually settled there still marks',
        );
    }

    /**
     * The locked read goes to the database, not to the identity map: a lock
     * cannot be taken over a copy the unit of work is already holding, and the
     * retention job asks for one precisely because its own copy may be stale.
     *
     * The transaction is not scenery. A row lock outside one would be released
     * by the very next statement, so Doctrine refuses to pretend - which is
     * what makes the retention job's transaction load-bearing rather than
     * decorative.
     */
    public function testTheLockedReadSeesTheRowRatherThanTheLoadedCopy(): void
    {
        $task = $this->storedTask();
        $this->repository->get($task->id());
        $this->connection()->executeStatement('UPDATE video_tasks SET status = ? WHERE id = ?', ['canceled', $task->id()->value]);

        $this->connection()->beginTransaction();

        try {
            $locked = $this->repository->getForUpdate($task->id());

            self::assertNotNull($locked);
            self::assertSame(VideoTaskStatus::CANCELED, $locked->status());
            self::assertNull($this->repository->getForUpdate(UuidValue::new()));
        } finally {
            $this->connection()->rollBack();
        }
    }

    /** Without one there is no lock to take, and a silent read would be a lie. */
    public function testTheLockedReadRefusesToRunOutsideATransaction(): void
    {
        $task = $this->storedTask();

        $this->expectException(TransactionRequiredException::class);

        $this->repository->getForUpdate($task->id());
    }

    /**
     * The enum is stored as its own value; a row written with anything else is a
     * corrupted row and has to say so rather than being read as some default.
     */
    public function testAnUnknownStatusInTheDatabaseIsRefused(): void
    {
        $task = $this->storedTask();
        $this->connection()->executeStatement('UPDATE video_tasks SET status = ? WHERE id = ?', ['exploded', $task->id()->value]);
        $this->entityManager->clear();

        $this->expectException(\ValueError::class);

        $this->repository->get($task->id());
    }

    /** MySQL enforces the column widths that SQLite quietly ignores. */
    #[Group('mysql')]
    public function testAUrlUpToTheColumnWidthIsStored(): void
    {
        $url = 'http://localhost/videos/'.str_repeat('a', 2000).'.mp4';

        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted($url, $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame($url, $loaded->finalVideoUrl());
    }

    private function storedTask(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->repository->save($task);

        return $task;
    }

    /** Settled, asked for a callback, never got one: what the sweep looks for. */
    private function storedTaskAwaitingCallback(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);
        $this->repository->save($task);

        return $task;
    }

    private function staleBefore(): DateTimeValue
    {
        return $this->now->minusSeconds(3600);
    }
}
