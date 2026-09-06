<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

use App\Task\Domain\Entity\VideoTask;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the notification of a task that just settled, when the task asked
 * for one.
 *
 * Only queues: the HTTP call happens in NotifyTaskCallbackHandler, on the
 * callbacks transport, so that a slow or broken endpoint costs the caller of
 * this class nothing and a failed delivery never touches the task's status.
 */
final readonly class TaskCallbacks
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    /**
     * @param int $generation the generation the transition that settled the
     *                        task produced, which is what the delivery is
     *                        recorded against
     */
    public function notify(VideoTask $task, int $generation): void
    {
        if (null === $task->callbackUrl()) {
            return;
        }

        $this->commandBus->dispatch(new NotifyTaskCallback($task->id()->value, $task->status()->value, $generation));
    }
}
