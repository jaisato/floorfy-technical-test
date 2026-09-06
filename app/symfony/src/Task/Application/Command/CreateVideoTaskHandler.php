<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\Transaction;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Domain\ValueObject\RenderOptions;
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
        $this->commandBus->dispatch(new ProcessVideoTaskCommand($task->id()->value));

        return $task->id()->value;
    }
}
