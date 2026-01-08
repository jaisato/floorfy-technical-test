<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Repository;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Infrastructure\Persistence\Doctrine\Entity\VideoTaskEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineVideoTaskRepository implements VideoTaskRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(VideoTask $task): void
    {
        $entity = $this->em->find(VideoTaskEntity::class, $task->id()->value);

        if (!$entity) {
            $entity = new VideoTaskEntity();
            $entity->id = $task->id()->value;
            $entity->createdAt = $task->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->payload = $task->payload();
        $entity->status = $task->status()->value;
        $entity->finalVideoUrl = $task->finalVideoUrl();
        $entity->errorMessage = $task->errorMessage();
        $entity->updatedAt = $task->updatedAt()->toDateTimeImmutable();

        $this->em->flush();
    }

    public function get(UuidValue $id): ?VideoTask
    {
        $entity = $this->em->find(VideoTaskEntity::class, $id->value);
        return $entity ? $this->toDomain($entity) : null;
    }

    private function toDomain(VideoTaskEntity $e): VideoTask
    {
        return VideoTask::rehydrate(
            UuidValue::fromString($e->id),
            is_array($e->payload) ? $e->payload : [],
            VideoTaskStatus::from($e->status),
            $e->finalVideoUrl,
            $e->errorMessage,
            DateTimeValue::fromDateTimeImmutable($e->createdAt),
            DateTimeValue::fromDateTimeImmutable($e->updatedAt),
        );
    }
}
