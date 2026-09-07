<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Messenger;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Command\AttemptInFlight;
use App\Task\Application\Command\ProcessVideoTaskCommand;
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
        private TaskCallbacks $callbacks,
        private AttemptInFlight $attempt,
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

        $generation = $this->attempt->generationOf($id);

        if (null === $generation && $this->attempt->ran($id)) {
            // The handler ran this task and lost the claim on the way: it was
            // canceled, or declared abandoned and taken over. There is no
            // attempt of this delivery's left to fail, and the unconditional
            // transition below is not a fallback for it - whatever is pending
            // or processing now is a replacement run, and failing that is
            // exactly the thing the generation exists to prevent. The message
            // is out of retries and its outcome already written by whoever took
            // the task off it.
            return;
        }

        // One conditional UPDATE, not a read followed by a save. A task that
        // finished on another delivery keeps its result - the failure being
        // reported is of a message that had nothing left to do - and a
        // cancellation that commits between the two steps used to be flushed
        // back as failed: the DELETE had already answered success, and the
        // client was told the task was canceled and then that it failed, with
        // a callback for each. Whoever's UPDATE lands first decides.
        $settled = $this->tasks->markFailedIfStillRunning(
            $id,
            self::reason($event->getThrowable()),
            // The attempt whose message this is, as the handler recorded it
            // when it handed the claim back. Null only when no attempt was ever
            // recorded - the worker died, or the message never reached the
            // handler - and then whatever is still running is what this failure
            // is about, which is the recovery this listener exists for.
            $generation,
            $this->clock->now(),
        );

        if (null === $settled) {
            return;
        }

        $task = $this->tasks->get($id);

        if (null !== $task) {
            $this->callbacks->notify($task, $settled);
        }
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
