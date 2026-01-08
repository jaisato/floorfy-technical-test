<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Repository;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Infrastructure\Persistence\Doctrine\Entity\PartialVideoEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePartialVideoRepository implements PartialVideoRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(PartialVideo $partial): void
    {
        $entity = $this->em->find(PartialVideoEntity::class, $partial->id()->value);

        if (!$entity) {
            $entity = new PartialVideoEntity();
            $entity->id = $partial->id()->value;
            $entity->taskId = $partial->taskId()->value;
            $entity->createdAt = $partial->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->imageUrl = $partial->imageUrl();
        $entity->transition = $partial->transition()->value;
        $entity->status = $partial->status()->value;
        $entity->videoPath = $partial->videoPath();
        $entity->errorMessage = $partial->errorMessage();
        $entity->updatedAt = $partial->updatedAt()->toDateTimeImmutable();

        $this->em->flush();
    }

    /** @return list<PartialVideo> */
    public function listByTaskId(UuidValue $taskId): array
    {
        $repo = $this->em->getRepository(PartialVideoEntity::class);
        $entities = $repo->findBy(['taskId' => $taskId->value], ['createdAt' => 'ASC']);

        $out = [];
        foreach ($entities as $entity) {
            $out[] = $this->toDomain($entity);
        }
        return $out;
    }

    private function toDomain(PartialVideoEntity $e): PartialVideo
    {
        return PartialVideo::rehydrate(
            UuidValue::fromString($e->id),
            UuidValue::fromString($e->taskId),
            $e->imageUrl,
            Transition::from($e->transition),
            PartialVideoStatus::from($e->status),
            $e->videoPath,
            $e->errorMessage,
            DateTimeValue::fromDateTimeImmutable($e->createdAt),
            DateTimeValue::fromDateTimeImmutable($e->updatedAt),
        );
    }
}
