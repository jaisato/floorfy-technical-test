<?php
declare(strict_types=1);

namespace App\Animation\Application\Query;

use App\Animation\Application\DTO\AnimationJobView;
use App\Animation\Domain\Port\AnimationJobRepository;
use App\Shared\Domain\ValueObject\UuidValue;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.query')]
final class GetAnimationJobHandler
{
    public function __construct(private AnimationJobRepository $repo)
    {
    }

    public function __invoke(GetAnimationJobQuery $q): ?AnimationJobView
    {
        $job = $this->repo->get(UuidValue::fromString($q->id));
        if (!$job) return null;

        return new AnimationJobView(
            id: $job->id()->value,
            imageUrl: $job->imageUrl(),
            status: $job->status()->value,
            videoUrl: $job->videoUrl(),
            errorMessage: $job->errorMessage(),
            createdAt: $job->createdAt()->toDateTimeImmutable()->format(DATE_ATOM),
            updatedAt: $job->updatedAt()->toDateTimeImmutable()->format(DATE_ATOM),
        );
    }
}
