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
     * Reads a task and holds its row until the caller's transaction ends.
     *
     * For the one caller that decides something from a task's state and then
     * spends time acting on it outside the database: the retention job reads
     * "failed, nothing running", deletes the files, and writes prunedAt. A
     * retry landing in that gap turns the files it deleted into the input of a
     * run already under way. Every other writer here settles its race with a
     * conditional UPDATE; this one cannot, because what it has to keep still is
     * not a column but the seconds it spends unlinking.
     */
    public function getForUpdate(UuidValue $id): ?VideoTask;

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
     * The claim's generation is what the caller then holds: a status says
     * "somebody is working on this" and is true again of the next worker, so
     * an attempt whose task was canceled and retried under it saw "processing"
     * and carried on writing over the replacement run. Every later write of
     * this attempt's carries the number back, and the row only accepts it
     * while it is still the same attempt.
     *
     * @return int|null the generation this claim produced, or null when the
     *                  caller must leave the task alone
     */
    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): ?int;

    /**
     * Hands a claimed task back so the next delivery of the message can retry it.
     *
     * @param int $generation the number claimForProcessing() returned
     *
     * @return int|null the generation the task now carries, which is what a
     *                  failure reported after this release is about; null when
     *                  this attempt no longer holds the claim and has nothing
     *                  to hand back
     */
    public function release(UuidValue $id, int $generation, DateTimeValue $now): ?int;

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
     * @param int $generation the number claimForProcessing() returned
     *
     * @return bool false when the claim is gone: the task was canceled, or it
     *              had been declared abandoned and taken by somebody else - by
     *              status or by generation, and the second is what catches a
     *              task that was canceled, retried and claimed again while this
     *              attempt was inside ffmpeg. The caller must stop either way.
     */
    public function renewLease(UuidValue $id, int $generation, DateTimeValue $now): bool;

    /**
     * Writes the finished video, but only over a task this worker still holds.
     *
     * Checking for a cancellation and then saving is two steps, and one that
     * lands in between is simply overwritten: the client is told the task was
     * canceled and then, a moment later, that it completed. As one conditional
     * UPDATE the cancellation wins, which is what the client was promised.
     *
     * @param int $generation the number claimForProcessing() returned
     *
     * @return int|null the generation the completed task now carries, which is
     *                  what its notification is recorded against; null when the
     *                  row was no longer this attempt's to finish
     */
    public function complete(UuidValue $id, string $finalVideoUrl, int $generation, DateTimeValue $now): ?int;

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
     * Takes an unclaimed task for this sweep, so the next one leaves it alone
     * for a whole window.
     *
     * Publishing again is safe to repeat - a task already being processed
     * refuses the claim - but repeating it is not free: a task waiting its turn
     * in a busy broker is still unclaimed and still untouched, so every run of
     * the documented cron published another copy of the same message, and a
     * backlog of long renders turned into a queue of no-ops behind them.
     * Bumping updatedAt is what takes it out of listUnclaimedSince() until the
     * window has passed again, and it says nothing else: for a pending task
     * that column is only ever read as "how long has this sat here".
     *
     * @return bool false when another sweep took it first, or a worker already
     *              claimed the task, in which case there is nothing to publish
     */
    public function claimRepublication(UuidValue $id, DateTimeValue $before, DateTimeValue $now): bool;

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
     * Takes the notification of a settled task for this sweep, so a run beside
     * it - or the next one, while the transport is still retrying the delivery
     * - leaves it alone.
     *
     * The condition is the listing's, applied again as an UPDATE: two sweeps
     * both read the row as owed and only the one whose write lands publishes.
     * Without it "not delivered yet" was read as "lost", and a delivery being
     * retried was published afresh by every run, so the client got the same
     * POST several times over.
     *
     * @param int           $generation the run the listing read, so a task
     *                                  retried and settled again in between
     *                                  cannot be claimed for the old one
     * @param DateTimeValue $before     the sweep's cutoff: an attempt older
     *                                  than this is stale and may be claimed
     *                                  again
     *
     * @return bool true when this caller took it
     */
    public function claimCallbackNotification(UuidValue $id, int $generation, DateTimeValue $before, DateTimeValue $now): bool;

    /**
     * Records that this run's notification has just been published, so the
     * recovery sweep counts it as attempted rather than lost.
     *
     * The sweep looks for a settled task whose callback was never delivered and
     * whose last attempt - if any - is older than the cutoff. The first
     * publication stamped nothing, so the only date it had to go on was the one
     * the task settled at: with a ten-minute window and a callback transport
     * that retries for about a quarter of an hour, the sweep published a second
     * notification at minute ten while the first was still being retried, and
     * the client got the same event twice. The publication is stamped the way
     * the sweep stamps its own, before it goes out.
     */
    public function markCallbackPublished(UuidValue $id, int $generation, DateTimeValue $now): void;

    /**
     * Records that the callback for a task was delivered, so the sweep above
     * stops offering it - but only while the task still stands where the
     * delivered notification said it did.
     *
     * A delivery takes as long as the endpoint takes, and a task can be
     * retried and settle again in that time. Written unconditionally, the
     * in-flight notification of the previous run answered for the new one: the
     * sweep looks for settled tasks whose callback was never delivered, this
     * row said it had been, and the client was never told the retry completed.
     *
     * The status alone does not identify the run - two runs of one task can
     * both end `failed`, and the first one's late delivery was then accepted
     * for the second - and neither does the instant it settled, which is a
     * MySQL DATETIME: cancel, retry and cancel again with no worker in between
     * puts both runs in the same second. The generation does: every transition
     * that starts or ends a run moves it on, so the number a notification
     * carries names one of them.
     *
     * @param string $event      the status the delivered notification announced
     * @param int    $generation the task's generation when the notification was read
     *
     * @return bool false when the task no longer stands there, so the mark was
     *              not written and the notification this run owes is still owed
     */
    public function markCallbackNotified(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool;

    /**
     * Records that this task's notification will not be delivered, so the sweep
     * stops offering it.
     *
     * A delivery failure the transport can retry is a delay; one it cannot is
     * an outcome. A callback URL the guard refuses is refused every time, and a
     * deployment with no signing secret cannot sign any notification, so the
     * handler refuses both outright instead of spending the retries. Nothing
     * then distinguished that row from one whose publish was lost - settled, a
     * callback asked for, none delivered - and the sweep published the same
     * doomed notification a cutoff later, and again, for as long as the task
     * existed.
     *
     * Fenced by the run, exactly like markCallbackNotified(): a task that
     * settled again while the delivery was being refused owes a fresh
     * notification, and this verdict is not that run's.
     *
     * @param string $event      the status the refused notification announced
     * @param int    $generation the task's generation when the notification was read
     *
     * @return bool false when the task no longer stands there, so nothing was
     *              written and the notification that run owes is still owed
     */
    public function markCallbackAbandoned(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool;

    /**
     * Forgets that a callback was ever delivered for this task.
     *
     * One timestamp per task, and a task can settle more than once: the run
     * that is retried settles again and owes the client another notification.
     * With the mark left over from the first run, that second notification was
     * the one publish the recovery sweep could never find - it looks for
     * settled tasks whose callback was never delivered, and this row said it
     * had been. Cleared when the task is queued again, the sweep covers every
     * run the same way.
     *
     * The sweep's claim goes with it, for the same reason: the new run's
     * notification must not wait out a cutoff because the previous run's was
     * once attempted.
     */
    public function clearCallbackNotification(UuidValue $id): void;

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
     * @return int|null the generation the canceled task now carries, which is
     *                  what its notification is recorded against; null when the
     *                  row was no longer pending or processing
     */
    public function cancel(UuidValue $id, DateTimeValue $now): ?int;

    /**
     * Writes the terminal failure, but only over a task that is still pending
     * or processing.
     *
     * The same arbitration as cancel(), from the other side. Read-then-save
     * let a cancellation that committed in between be flushed back as failed:
     * the DELETE had already answered success, the row said failed, and the
     * client was told both. Whoever's UPDATE lands first decides.
     *
     * @param int|null $generation the attempt this failure belongs to, as
     *                             release() reported it. The row must still be
     *                             on that generation: the failure is reported
     *                             after the handler has handed the claim back,
     *                             and a duplicate delivery can have claimed the
     *                             task in between - failing whatever is running
     *                             then fails a healthy run. Null when no
     *                             attempt can be named (the worker died, or the
     *                             message never reached the handler), where
     *                             failing what is still running is the recovery
     *                             this exists for.
     *
     * @return int|null the generation the failed task now carries, which is
     *                  what its notification is recorded against; null when the
     *                  row was no longer pending or processing, or no longer
     *                  the attempt named
     */
    public function markFailedIfStillRunning(UuidValue $id, string $errorMessage, ?int $generation, DateTimeValue $now): ?int;

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
