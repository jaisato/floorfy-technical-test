<?php

declare(strict_types=1);

namespace App\Composition\Application\Query;

use App\Composition\Application\DTO\CompositionJobView;
use App\Composition\Domain\Port\CompositionJobRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.query')]
final class GetCompositionJobHandler
{
    public function __construct(private readonly CompositionJobRepository $repo)
    {
    }

    public function __invoke(GetCompositionJobQuery $query): CompositionJobView
    {
        $job = $this->repo->get($query->jobId);

        return new CompositionJobView(
            id: $job->id()->value,
            status: $job->status()->value,
            animationJobIds: $job->animationVideoUrls(),
            outputUrl: $job->outputUrl(),
            error: $job->errorMessage(),
            createdAt: $job->createdAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $job->updatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
