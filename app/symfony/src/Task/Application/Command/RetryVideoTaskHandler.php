<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\Transaction;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Exception\TaskNotFound;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
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
    ) {
    }

    public function __invoke(RetryVideoTaskCommand $command): void
    {
        $id = UuidValue::tryFromString($command->taskId);
        $task = null === $id ? null : $this->tasks->get($id);

        if (null === $id || null === $task) {
            throw TaskNotFound::withId($command->taskId);
        }

        $now = $this->clock->now();

        $this->transaction->run(function () use ($task, $now): void {
            $this->requeue($task, $now);
        });

        $this->commandBus->dispatch(new ProcessVideoTaskCommand($task->id()->value));
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
    }
}
