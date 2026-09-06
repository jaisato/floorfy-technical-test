<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Task\Domain\Port\VideoTaskRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cancels a task that has not finished.
 *
 * The domain object says whether the cancellation is allowed from the status
 * the task was read with; the repository then writes it conditionally, so a
 * worker completing the same task in the same instant cannot have its result
 * overwritten. When the write is refused, the answer is built from the status
 * the row has now, which is the one the caller is actually up against.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class CancelVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private Clock $clock,
        private TaskCallbacks $callbacks,
    ) {
    }

    public function __invoke(CancelVideoTaskCommand $command): void
    {
        $id = UuidValue::tryFromString($command->taskId);
        $task = null === $id ? null : $this->tasks->get($id);

        if (null === $id || null === $task) {
            throw TaskNotFound::withId($command->taskId);
        }

        $now = $this->clock->now();
        $task->cancel($now);

        if (!$this->tasks->cancel($id, $now)) {
            throw InvalidTaskTransition::between($this->tasks->currentStatus($id) ?? VideoTaskStatus::CANCELED, VideoTaskStatus::CANCELED);
        }

        $this->callbacks->notify($task);
    }
}
