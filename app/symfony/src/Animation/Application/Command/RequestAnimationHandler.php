<?php
declare(strict_types=1);

namespace App\Animation\Application\Command;

use App\Animation\Domain\Entity\AnimationJob;
use App\Animation\Domain\Port\AnimationJobRepository;
use App\Animation\Domain\Port\VeoAnimator;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\UrlValue;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final class RequestAnimationHandler
{
    public function __construct(
        private AnimationJobRepository $repo,
        private VeoAnimator $veo,
        private Clock $clock,
        private MessageBusInterface $commandBus,
    ) {}

    public function __invoke(RequestAnimationCommand $cmd): string
    {
        $now = $this->clock->now();

        $job = AnimationJob::create($cmd->prompt, $cmd->imageUrl, $now);

        $operationId = $this->veo->requestAnimation(UrlValue::fromString($job->imageUrl()), $cmd->prompt);
        $job->markRunning($operationId, $now);

        $this->repo->save($job);

        // async tracking
        $this->commandBus->dispatch(new TrackAnimationStatusCommand($operationId));

        return $job->id()->value;
    }
}

