<?php

declare(strict_types=1);

namespace App\Task\Application\Recovery;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Command\ProcessVideoTaskCommand;
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
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
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

        foreach ($this->tasks->listUnclaimedSince($before, $limit) as $task) {
            if (!$dryRun) {
                $this->commandBus->dispatch(new ProcessVideoTaskCommand($task->id()->value));
            }

            $requeued[] = $task->id()->value;
        }

        foreach ($this->tasks->listAwaitingCallback($before, $limit) as $task) {
            if (!$dryRun) {
                $this->commandBus->dispatch(new NotifyTaskCallback($task->id()->value, $task->status()->value));
            }

            $renotified[] = $task->id()->value;
        }

        if ([] !== $requeued || [] !== $renotified) {
            $this->logger->warning($dryRun ? 'Recovery run (dry run)' : 'Recovery run', [
                'requeued' => \count($requeued),
                'renotified' => \count($renotified),
                'before' => $before->toIso8601(),
            ]);
        }

        return new RecoveryReport($requeued, $renotified, $dryRun);
    }
}
