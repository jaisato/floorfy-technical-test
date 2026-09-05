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
use App\Task\Infrastructure\Persistence\Doctrine\Entity\VideoTaskEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePartialVideoRepository implements PartialVideoRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(PartialVideo $partial): void
    {
        $this->sync($partial);
        $this->em->flush();
    }

    public function saveAll(array $partials): void
    {
        foreach ($partials as $partial) {
            $this->sync($partial);
        }

        $this->em->flush();
    }

    public function listByTaskId(UuidValue $taskId): array
    {
        /** @var list<PartialVideoEntity> $entities */
        $entities = $this->em->getRepository(PartialVideoEntity::class)->findBy(
            ['task' => $taskId->value],
            ['position' => 'ASC'],
        );

        return array_map(
            fn (PartialVideoEntity $entity): PartialVideo => $this->toDomain($entity, $taskId),
            $entities,
        );
    }

    private function sync(PartialVideo $partial): void
    {
        $entity = $this->em->find(PartialVideoEntity::class, $partial->id()->value);

        if (!$entity instanceof PartialVideoEntity) {
            $entity = new PartialVideoEntity();
            $entity->id = $partial->id()->value;
            // A reference, not a fetch: only the foreign key is written, so
            // there is no reason to load the parent row.
            $task = $this->em->getReference(VideoTaskEntity::class, $partial->taskId()->value);

            if (!$task instanceof VideoTaskEntity) {
                throw new \RuntimeException(\sprintf('No existe la tarea "%s" a la que pertenece el parcial.', $partial->taskId()->value));
            }

            $entity->task = $task;
            $entity->imageUrl = $partial->imageUrl();
            $entity->transition = $partial->transition()->value;
            $entity->position = $partial->position();
            $entity->createdAt = $partial->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->status = $partial->status()->value;
        $entity->videoPath = $partial->videoPath();
        $entity->errorMessage = $partial->errorMessage();
        $entity->updatedAt = $partial->updatedAt()->toDateTimeImmutable();
    }

    private function toDomain(PartialVideoEntity $e, UuidValue $taskId): PartialVideo
    {
        return PartialVideo::rehydrate(
            UuidValue::fromString($e->id),
            $taskId,
            $e->imageUrl,
            Transition::from($e->transition),
            $e->position,
            PartialVideoStatus::from($e->status),
            $e->videoPath,
            $e->errorMessage,
            DateTimeValue::fromDateTimeImmutable($e->createdAt),
            DateTimeValue::fromDateTimeImmutable($e->updatedAt),
        );
    }
}
