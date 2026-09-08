<?php

declare(strict_types=1);

namespace App\Task\Application\Recovery;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Application\Command\TaskLease;
use App\Task\Domain\Port\VideoTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Puts back the messages the broker never got.
 *
 * The queue is RabbitMQ, not this database, so a publish cannot be part of the
 * transaction that writes the row it announces. Publishing inside the
 * transaction would announce work that may yet roll back, so both publishes -
 * "process this task" and "deliver this callback" - happen just after their
 * commit. A process that dies in that gap leaves the row behind with nothing
 * pointing at it: a task stuck at "pending" that no worker will ever claim, or
 * a finished task whose client is never told.
 *
 * This finds those two and publishes again, which is what makes a lost message
 * a delay instead of a loss. Both are safe to repeat: a task already being
 * processed refuses the claim, and a callback already delivered is recorded as
 * such and no longer turns up here.
 *
 * The grace period matters. A task published a second ago is not stuck, it is
 * new, and re-publishing it would only have two workers race for a claim one
 * of them is going to lose. Nothing is considered until it has sat untouched
 * for the whole window.
 */
final readonly class RecoverTasks
{
    public const int DEFAULT_LIMIT = 100;

    public function __construct(
        private VideoTaskRepository $tasks,
        private MessageBusInterface $commandBus,
        private Clock $clock,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
        #[Autowire(param: 'app.task_lease_seconds')]
        private int $leaseSeconds = 0,
        #[Autowire(param: 'app.ffmpeg_animate_timeout')]
        private int $animateTimeoutSeconds = 0,
        #[Autowire(param: 'app.ffmpeg_compose_timeout')]
        private int $composeTimeoutSeconds = 0,
    ) {
    }

    /**
     * @param positive-int $limit how many of each kind at most, so one run has
     *                            a bounded cost whatever the backlog is
     */
    public function run(DateTimeValue $before, bool $dryRun = false, int $limit = self::DEFAULT_LIMIT): RecoveryReport
    {
        $requeued = [];
        $renotified = [];

        // First, because it is what makes the loop below able to see them. A
        // worker killed mid-render leaves its task at "processing" with a fresh
        // lease, and the broker redelivers its message at once: the next
        // worker's claim is refused for exactly that reason, refusing reads as
        // "somebody else has it", and the delivery is acknowledged - so the
        // only message pointing at the task is gone before the lease has even
        // expired, and nothing was left to notice when it did.
        //
        // The cutoff is the lease, not this sweep's window: a claim younger
        // than the lease belongs to a worker that is very probably still
        // rendering, and taking it would restart work that is being done.
        $released = $dryRun ? [] : $this->tasks->releaseStaleClaims(
            $this->clock->now()->minusSeconds(TaskLease::seconds(
                $this->leaseSeconds,
                $this->animateTimeoutSeconds,
                $this->composeTimeoutSeconds,
            )),
            $limit,
        );

        foreach ($this->tasks->listUnclaimedSince($before, $limit) as $task) {
            // Claimed before publishing, exactly as the callback below is. A
            // task waiting its turn in a busy broker is still unclaimed and
            // still untouched, so without this every run of the documented
            // cron published another copy of the same message and a backlog of
            // long renders grew a queue of no-ops behind it. A dry run claims
            // nothing: it reports what a real run would do, and must leave the
            // sweep able to do it.
            if (!$dryRun && !$this->tasks->claimRepublication($task->id(), $before, $this->clock->now())) {
                continue;
            }

            if (!$dryRun && !$this->publish(new ProcessVideoTaskCommand($task->id()->value), $task->id())) {
                continue;
            }

            $requeued[] = $task->id()->value;
        }

        foreach ($this->tasks->listAwaitingCallback($before, $limit) as $task) {
            // Claimed before publishing, exactly as a task claim works. A
            // notification the transport is still retrying has not been
            // delivered and has not been lost either; without the claim every
            // sweep in between published another one and the client got the
            // same POST again and again. A dry run claims nothing: it reports
            // what a real run would do, and must leave the sweep able to do it.
            // The generation goes into the claim and into the message: it is
            // the run this sweep read, so a task retried and settled again in
            // between loses the claim rather than being renotified about the
            // run before it - and the delivery is recorded against that run
            // and no other.
            if (!$dryRun && !$this->tasks->claimCallbackNotification($task->id(), $task->runGeneration(), $before, $this->clock->now())) {
                continue;
            }

            if (!$dryRun && !$this->publish(new NotifyTaskCallback($task->id()->value, $task->status()->value, $task->runGeneration()), $task->id())) {
                continue;
            }

            $renotified[] = $task->id()->value;
        }

        if ([] !== $requeued || [] !== $renotified || [] !== $released) {
            $this->logger->warning($dryRun ? 'Recovery run (dry run)' : 'Recovery run', [
                'requeued' => \count($requeued),
                'renotified' => \count($renotified),
                'released' => \count($released),
                'before' => $before->toIso8601(),
            ]);
        }

        return new RecoveryReport($requeued, $renotified, $dryRun, array_map(
            static fn (UuidValue $id): string => $id->value,
            $released,
        ));
    }

    /**
     * Publishes one recovered message, and lets the rest of the run continue if
     * it cannot.
     *
     * The two kinds travel on separately configured transports, so a broker
     * outage or a bad DSN on one of them says nothing about the other. Thrown
     * out of the loop, the first task whose publish failed ended the run before
     * the callbacks were looked at - and since a stale pending task stays stale
     * until it is republished, that meant perfectly healthy notifications were
     * never recovered for as long as the fault lasted, which is exactly when
     * they are most likely to be owed.
     *
     * The claim above is already spent for this one; the next sweep past the
     * cutoff takes it again, which is what a lost publish looks like to this
     * job either way.
     */
    private function publish(object $message, UuidValue $taskId): bool
    {
        try {
            $this->commandBus->dispatch($message);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Recovery could not publish a message', [
                'task_id' => $taskId->value,
                'message' => $message::class,
                'exception' => $e::class,
                'reason' => Urls::scrub($e->getMessage()),
            ]);

            return false;
        }
    }
}
