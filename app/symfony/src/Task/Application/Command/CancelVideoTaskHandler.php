<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Task\Domain\Port\VideoTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
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

        $settled = $this->tasks->cancel($id, $now);

        if (null === $settled) {
            throw InvalidTaskTransition::between($this->tasks->currentStatus($id) ?? VideoTaskStatus::CANCELED, VideoTaskStatus::CANCELED);
        }

        // The cancellation is committed; the notification is queued after it,
        // and a broker that refuses the publish is not this request's failure.
        // RecoverTasks looks for exactly the row this leaves - settled, a
        // callback asked for, none delivered - and publishes it again.
        //
        // Answered 5xx, as this used to be, the caller could never reach a
        // successful answer for an operation that had succeeded: the row is
        // already canceled, so the retry of the DELETE came back 409.
        try {
            $this->callbacks->notify($task, $settled);
        } catch (\Throwable $e) {
            $this->logger->error('Task canceled but its notification was not published; the recovery sweep will publish it', [
                'task_id' => $id->value,
                'error' => Urls::scrub($e->getMessage()),
                'cause' => get_debug_type($e),
            ]);
        }
    }
}
