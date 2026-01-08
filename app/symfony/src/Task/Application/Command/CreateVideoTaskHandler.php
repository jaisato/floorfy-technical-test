<?php
declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final class CreateVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private MessageBusInterface $commandBus,
    ) {}

    public function __invoke(CreateVideoTaskCommand $cmd): string
    {
        $now = $this->clock->now();

        $task = VideoTask::create($cmd->payload, $now);
        $this->tasks->save($task);

        foreach ($cmd->images as $image) {
            $url = (string) ($image['url'] ?? '');
            $transition = Transition::from((string) ($image['transition'] ?? 'pan'));
            $partial = PartialVideo::create($task->id(), $url, $transition, $now);
            $this->partials->save($partial);
        }

        $this->commandBus->dispatch(new ProcessVideoTaskCommand($task->id()->value));

        return $task->id()->value;
    }
}
