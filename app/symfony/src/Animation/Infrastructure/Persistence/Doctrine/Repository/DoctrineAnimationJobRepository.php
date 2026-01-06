<?php
declare(strict_types=1);

namespace App\Animation\Infrastructure\Persistence\Doctrine\Repository;

use App\Animation\Domain\Entity\AnimationJob;
use App\Animation\Domain\Enum\AnimationStatus;
use App\Animation\Domain\Port\AnimationJobRepository;
use App\Animation\Infrastructure\Persistence\Doctrine\Entity\AnimationJobEntity;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineAnimationJobRepository implements AnimationJobRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function save(AnimationJob $job): void
    {
        $entity = $this->em->find(AnimationJobEntity::class, (string)$job->id()->value);

        if (!$entity) {
            $entity = new AnimationJobEntity();
            $entity->id = (string)$job->id()->value;
            $entity->createdAt = $job->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->prompt = $job->prompt();
        $entity->imageUrl = $job->imageUrl();
        $entity->operationId = $job->veoOperationId();
        $entity->status = $job->status()->value;
        $entity->videoUrl = $job->videoUrl();
        $entity->error = $job->errorMessage();
        $entity->updatedAt = $job->updatedAt()->toDateTimeImmutable();

        $this->em->flush();
    }

    public function get(UuidValue $id): ?AnimationJob
    {
        $entity = $this->em->find(AnimationJobEntity::class, $id->value);
        return $entity ? $this->toDomain($entity) : null;
    }

    private function toDomain(AnimationJobEntity $e): AnimationJob
    {
        return AnimationJob::rehydrate(
            UuidValue::fromString($e->id),
            $e->prompt ?? '',
            $e->imageUrl,
            $e->operationId,
            AnimationStatus::from($e->status),
            $e->videoUrl,
            $e->error,
            DateTimeValue::fromDateTimeImmutable($e->createdAt),
            DateTimeValue::fromDateTimeImmutable($e->updatedAt),
        );
    }

    public function getByOperationId(string $operationId): ?AnimationJob
    {
        $repo = $this->em->getRepository(AnimationJobEntity::class);
        $entity = $repo->findOneBy(['operationId' => $operationId]);
        return $entity ? $this->toDomain($entity) : null;
    }
}
