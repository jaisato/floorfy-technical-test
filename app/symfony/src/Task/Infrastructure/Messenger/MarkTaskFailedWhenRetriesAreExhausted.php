<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Messenger;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Writes the terminal "failed" status, and only then.
 *
 * The handler itself must not: it runs once per delivery, and a task marked
 * failed on the first error is a task the remaining retries can no longer
 * touch. This fires when Messenger has decided there will be no further
 * attempt, so the status matches what actually happened to the message.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class MarkTaskFailedWhenRetriesAreExhausted
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private Clock $clock,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof ProcessVideoTaskCommand) {
            return;
        }

        $id = UuidValue::tryFromString($message->taskId);

        if (null === $id) {
            return;
        }

        $task = $this->tasks->get($id);

        // A task that finished on another delivery keeps its result: the
        // failure being reported is of a message that had nothing left to do.
        // A canceled one was stopped on purpose, which is not a failure either.
        if (null === $task || \in_array($task->status(), [VideoTaskStatus::COMPLETED, VideoTaskStatus::CANCELED], true)) {
            return;
        }

        $task->markFailed(self::reason($event->getThrowable()), $this->clock->now());
        $this->tasks->save($task);
    }

    /**
     * The stored reason is served to API clients, so only messages that were
     * written for that survive; anything else is reported generically.
     */
    private static function reason(\Throwable $throwable): string
    {
        // Messenger wraps what the handler threw, and the handler wraps what a
        // port threw, so the explanation worth keeping is somewhere down the
        // chain rather than at the top of it.
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof ClientSafe) {
                return $cause->getMessage();
            }
        }

        return 'El procesamiento de la tarea no pudo completarse.';
    }
}
