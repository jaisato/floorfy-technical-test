<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Application\Transaction\Transaction;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Exception\TaskNotFound;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a failed or canceled task for another run.
 *
 * Parts that were completed are kept, file and all: the worker reuses them and
 * only renders what is missing. Parts that failed go back to pending so the
 * attempt picks them up, and the task's error is cleared because what it
 * reports from now on is the new attempt.
 *
 * The task and its parts change in one transaction, and the message is
 * published only once that has committed - the same reasoning as creation: a
 * message for rows a worker cannot see yet is a message it drops.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class RetryVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private Transaction $transaction,
        private MessageBusInterface $commandBus,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RetryVideoTaskCommand $command): void
    {
        $id = UuidValue::tryFromString($command->taskId);

        // Outside the transaction, so an unknown id is a 404 that locks nothing.
        if (null === $id || null === $this->tasks->get($id)) {
            throw TaskNotFound::withId($command->taskId);
        }

        $now = $this->clock->now();

        $this->transaction->run(function () use ($id, $now): void {
            // Read again under the row lock, because everything below is
            // decided from this state and then written back as a whole
            // aggregate - the one thing a conditional UPDATE cannot express
            // here, since the parts move with the task.
            //
            // Two retries arriving together both read "failed" outside the
            // lock, both passed the transition check, and the second flushed
            // its stale copy back to pending - over a task the first retry's
            // message had already put into processing, which left it claimable
            // a second time and two workers rendering into the same files. The
            // lock also holds off the retention job, which decides what to
            // delete from the very same state.
            $task = $this->tasks->getForUpdate($id) ?? throw TaskNotFound::withId($id->value);

            $this->requeue($task, $now);
        });

        // Published only once the rows are committed, and a publish that fails
        // here is not this request's failure - exactly as it is not for a task
        // being created. RecoverTasks looks for the row this leaves behind
        // (pending, untouched since the cutoff) and publishes it again.
        //
        // Answered 5xx, the caller was worse off than with the crash the sweep
        // already covers: the task is queued and no longer failed or canceled,
        // so the retry of the request that reported the error came back 409 and
        // the client had no way to reach a successful answer for an operation
        // that had succeeded.
        try {
            $this->commandBus->dispatch(new ProcessVideoTaskCommand($id->value));
        } catch (\Throwable $e) {
            $this->logger->error('Task queued for another run but not published; the recovery sweep will publish it', [
                'task_id' => $id->value,
                'error' => Urls::scrub($e->getMessage()),
                'cause' => get_debug_type($e),
            ]);
        }
    }

    private function requeue(VideoTask $task, DateTimeValue $now): void
    {
        // The transition check comes first: a task that cannot be retried must
        // not have its parts touched either.
        $task->retry($now);

        $reset = [];
        foreach ($this->partials->listByTaskId($task->id()) as $partial) {
            if ($partial->isCompleted()) {
                continue;
            }

            $partial->markPending($now);
            $reset[] = $partial;
        }

        if ([] !== $reset) {
            $this->partials->saveAll($reset);
        }

        $this->tasks->save($task);

        // The delivery mark belongs to the run that produced it. This run owes
        // the client a notification of its own, and the recovery sweep can only
        // find a lost one while the mark is empty.
        $this->tasks->clearCallbackNotification($task->id());
    }
}
