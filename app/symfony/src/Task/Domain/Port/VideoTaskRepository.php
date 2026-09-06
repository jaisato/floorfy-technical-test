<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;

interface VideoTaskRepository
{
    public function save(VideoTask $task): void;

    public function get(UuidValue $id): ?VideoTask;

    /**
     * Takes exclusive ownership of a task for one processing attempt.
     *
     * Reading the row and then writing "processing" back is not enough: two
     * workers handed the same message (a broker redelivery while the first is
     * still running) both read "pending", both proceed, and both write over
     * each other's partials. This is a single conditional UPDATE, so exactly
     * one of them changes a row and the other is told to drop the message.
     *
     * A task whose worker died mid-attempt would otherwise stay "processing"
     * for ever and never be picked up again, so a claim older than the lease is
     * treated as abandoned and can be taken over.
     *
     * @return bool true when the caller now owns the task, false when it must
     *              leave the task alone
     */
    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): bool;

    /**
     * Hands a claimed task back so the next delivery of the message can retry it.
     *
     * @return bool true when a claim was actually released
     */
    public function release(UuidValue $id, DateTimeValue $now): bool;

    /**
     * Says "still working on it" and pushes the claim's deadline forward.
     *
     * The lease exists so a task whose worker died is not stuck for ever, and
     * it was being measured from the moment the claim was taken. A run has no
     * fixed length, though - twenty images take as long as they take - so a
     * long but perfectly healthy attempt outlived its own lease and a second
     * worker took the task off it, rendering everything twice. Renewed between
     * parts, the deadline means "no progress since", which is the thing the
     * lease was ever meant to detect.
     *
     * @return bool false when the claim is gone: the task was canceled, or it
     *              had been declared abandoned and taken by somebody else. The
     *              caller must stop either way.
     */
    public function renewLease(UuidValue $id, DateTimeValue $now): bool;

    /**
     * Writes the finished video, but only over a task this worker still holds.
     *
     * Checking for a cancellation and then saving is two steps, and one that
     * lands in between is simply overwritten: the client is told the task was
     * canceled and then, a moment later, that it completed. As one conditional
     * UPDATE the cancellation wins, which is what the client was promised.
     *
     * @return bool false when the row was no longer "processing"
     */
    public function complete(UuidValue $id, string $finalVideoUrl, DateTimeValue $now): bool;

    /**
     * Tasks nothing is working on and nothing will: still pending, untouched
     * since the cutoff.
     *
     * The queue is a broker, not this database, so publishing the "process
     * this" message cannot be part of the transaction that writes the task. It
     * is published right after the commit - never before, which would announce
     * a task that may yet roll back - and a process that dies in between leaves
     * a task nobody will ever pick up. This is how those are found again, which
     * turns a lost message into a delay rather than a task lost for good.
     *
     * @param positive-int $limit
     *
     * @return list<VideoTask>
     */
    public function listUnclaimedSince(DateTimeValue $before, int $limit): array;

    /**
     * Settled tasks that asked for a callback and never got one, untouched
     * since the cutoff: the same lost-publish problem, at the other end.
     *
     * @param positive-int $limit
     *
     * @return list<VideoTask>
     */
    public function listAwaitingCallback(DateTimeValue $before, int $limit): array;

    /**
     * Records that the callback for a task was delivered, so the sweep above
     * stops offering it.
     */
    public function markCallbackNotified(UuidValue $id, DateTimeValue $now): void;

    /**
     * Writes the cancellation, but only over a task that is still pending or
     * processing.
     *
     * The domain object decides whether a cancellation is allowed; this is the
     * database arbitrating against a worker finishing the very same task at the
     * same moment. Read-then-save would let "completed", written a millisecond
     * earlier, be overwritten with "canceled" - a video that exists, reported
     * as abandoned.
     *
     * @return bool false when the row was no longer pending or processing
     */
    public function cancel(UuidValue $id, DateTimeValue $now): bool;

    /**
     * The status the row has right now, read from the database rather than
     * from anything already loaded.
     *
     * The worker holds a task for minutes and asks this between parts: a copy
     * loaded when the attempt started cannot show a cancellation that arrived
     * since.
     */
    public function currentStatus(UuidValue $id): ?VideoTaskStatus;

    /**
     * Settled tasks last touched before the cutoff whose videos are still on
     * disk: what the retention job has to clean up.
     *
     * Only completed, failed and canceled tasks: deleting the files of one
     * that is still being rendered would break the run in progress.
     *
     * @param positive-int $limit how many to return at most, so one run has a
     *                            bounded cost whatever the backlog is
     *
     * @return list<VideoTask>
     */
    public function listPrunable(DateTimeValue $before, int $limit): array;
}
