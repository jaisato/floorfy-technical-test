<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\DTO\VideoTaskSummaryView;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers one callback notification.
 *
 * The body is the task's summary as it is now, not as it was when the event
 * happened: a client that receives the notification late still gets the truth.
 * The event itself travels in the request headers, so the two are never
 * confused when a task was retried in between.
 *
 * A failure here never touches the task. It is either rethrown, so the
 * transport retries it, or refused outright when no retry could help - a URL
 * the guard rejects, a deployment with no signing secret.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class NotifyTaskCallbackHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private CallbackDelivery $delivery,
        private VideoUrls $urls,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyTaskCallback $message): void
    {
        $id = UuidValue::tryFromString($message->taskId);
        $task = null === $id ? null : $this->tasks->get($id);

        if (null === $id || null === $task) {
            throw new UnrecoverableMessageHandlingException(\sprintf('No existe la tarea "%s" cuya notificación había que entregar.', $message->taskId));
        }

        $url = $task->callbackUrl();

        if (null === $url) {
            // Nothing to deliver to; acknowledging is right.
            return;
        }

        $summary = VideoTaskSummaryView::fromTask($task, $this->partials->listByTaskId($id), $this->urls->absolute($task->finalVideoUrl()));

        try {
            $this->delivery->deliver(new CallbackRequest($url, $id->value, $message->event, $summary->toArray()));
        } catch (CallbackDeliveryFailed $e) {
            $this->logger->warning('Callback delivery failed', [
                'task_id' => $id->value,
                'event' => $message->event,
                'permanent' => $e->isPermanent(),
                'message' => $e->getMessage(),
            ]);

            if ($e->isPermanent()) {
                throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
            }

            throw $e;
        }

        $this->logger->info('Callback delivered', ['task_id' => $id->value, 'event' => $message->event]);
    }
}
