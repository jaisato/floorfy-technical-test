<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

use App\Shared\Application\Clock\Clock;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Port\VideoTaskRepository;
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
    public function __construct(
        private MessageBusInterface $commandBus,
        private VideoTaskRepository $tasks,
        private Clock $clock,
    ) {
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

        // Stamped before it goes, the way the recovery sweep stamps its own
        // publications. The sweep offers a settled task whose callback was
        // never delivered and whose last attempt is older than the cutoff;
        // with nothing stamped here the only date it had was the one the task
        // settled at, so with a ten-minute window and a transport that retries
        // a callback for about a quarter of an hour it published a second
        // notification at minute ten, on top of the one still being retried,
        // and the client got the same event twice.
        //
        // Before rather than after: a publish that then fails leaves the sweep
        // a cutoff late, which is what a lost publish costs anyway, while a
        // stamp written after one that succeeded is a window in which the
        // process can die and the duplicate happens all the same.
        $this->tasks->markCallbackPublished($task->id(), $generation, $this->clock->now());

        $this->commandBus->dispatch(new NotifyTaskCallback($task->id()->value, $task->status()->value, $generation));
    }
}
