<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Application\Transaction\Transaction;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Domain\ValueObject\RenderOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class CreateVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private Transaction $transaction,
        private MessageBusInterface $commandBus,
        private RenderOptions $defaultRenderOptions,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateVideoTaskCommand $command): string
    {
        $now = $this->clock->now();

        // The task row and its parts are one unit: a task written without its
        // parts is a task the worker can never finish, and it used to be
        // reachable by any error in the middle of the loop.
        // The options are resolved here, once, and stored: a task rendered
        // today and retried next month must come out the same, even if the
        // deployment's defaults changed in between.
        $options = $this->defaultRenderOptions->with($command->options);

        $task = $this->transaction->run(function () use ($command, $now, $options): VideoTask {
            $task = VideoTask::create(['images' => $command->images], $now, $command->callbackUrl, $options);
            $this->tasks->save($task);

            $partials = [];
            foreach (array_values($command->images) as $position => $image) {
                $partials[] = PartialVideo::create(
                    $task->id(),
                    $image['url'],
                    Transition::from($image['transition']),
                    $position,
                    $now,
                    $image['duration'] ?? null,
                );
            }

            $this->partials->saveAll($partials);

            return $task;
        });

        // Published only once the rows are committed. Dispatching first - or
        // inside the transaction - can hand the worker an id it cannot read,
        // or leave a message behind for a task that was rolled back.
        //
        // And a publish that fails here is not this request's last chance, so
        // it is not this request's failure. RecoverTasks looks for exactly the
        // row this leaves - pending, untouched since the cutoff - and
        // publishes it again; that sweep is why a lost publish is a delay
        // rather than a loss, and a broker that was down for one dispatch is
        // the case it was written for.
        //
        // Answered 5xx, as this used to be, it was worse than the crash the
        // sweep already covers: the idempotency key is released on a retryable
        // status, so the client's retry created a *second* task with all its
        // rendering, while the sweep published the first.
        try {
            $this->commandBus->dispatch(new ProcessVideoTaskCommand($task->id()->value));
        } catch (\Throwable $e) {
            $this->logger->error('Task created but not published; the recovery sweep will publish it', [
                'task_id' => $task->id()->value,
                'error' => Urls::scrub($e->getMessage()),
                'cause' => get_debug_type($e),
            ]);
        }

        return $task->id()->value;
    }
}
