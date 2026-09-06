<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Repository;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Infrastructure\Persistence\Doctrine\Entity\VideoTaskEntity;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineVideoTaskRepository implements VideoTaskRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(VideoTask $task): void
    {
        $entity = $this->em->find(VideoTaskEntity::class, $task->id()->value);

        if (!$entity instanceof VideoTaskEntity) {
            $entity = new VideoTaskEntity();
            $entity->id = $task->id()->value;
            $entity->payload = $task->payload();
            $entity->callbackUrl = $task->callbackUrl();
            $entity->createdAt = $task->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->status = $task->status()->value;
        $entity->finalVideoUrl = $task->finalVideoUrl();
        $entity->errorMessage = $task->errorMessage();
        $entity->updatedAt = $task->updatedAt()->toDateTimeImmutable();

        $this->em->flush();
    }

    public function get(UuidValue $id): ?VideoTask
    {
        $entity = $this->em->find(VideoTaskEntity::class, $id->value);

        return $entity instanceof VideoTaskEntity ? $this->toDomain($entity) : null;
    }

    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): bool
    {
        // One statement, so the database arbitrates. Read-then-write would let
        // two workers both see "pending" and both start.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :processing, updated_at = :now
                 WHERE id = :id
                   AND (status = :pending
                        OR status = :failed
                        OR (status = :processing AND updated_at <= :stale))
                SQL,
            [
                'processing' => VideoTaskStatus::PROCESSING->value,
                'pending' => VideoTaskStatus::PENDING->value,
                'failed' => VideoTaskStatus::FAILED->value,
                'now' => $now->toDateTimeImmutable(),
                'stale' => $staleBefore->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'processing' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'failed' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'stale' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
            ],
        );

        if ($affected < 1) {
            return false;
        }

        $this->forgetCachedCopy($id);

        return true;
    }

    public function release(UuidValue $id, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :pending, updated_at = :now
                 WHERE id = :id AND status = :processing
                SQL,
            [
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
            ],
        );

        if ($affected < 1) {
            return false;
        }

        $this->forgetCachedCopy($id);

        return true;
    }

    public function cancel(UuidValue $id, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :canceled, updated_at = :now
                 WHERE id = :id AND (status = :pending OR status = :processing)
                SQL,
            [
                'canceled' => VideoTaskStatus::CANCELED->value,
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'canceled' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
            ],
        );

        if ($affected < 1) {
            return false;
        }

        $this->forgetCachedCopy($id);

        return true;
    }

    public function currentStatus(UuidValue $id): ?VideoTaskStatus
    {
        // Straight to the connection on purpose: find() would answer from the
        // identity map with whatever status the row had when it was loaded.
        $status = $this->em->getConnection()->fetchOne(
            'SELECT status FROM video_tasks WHERE id = :id',
            ['id' => $id->value],
            ['id' => ParameterType::STRING],
        );

        return \is_string($status) ? VideoTaskStatus::from($status) : null;
    }

    /**
     * The statements above go straight to the connection, so an instance the
     * unit of work is already holding would keep reporting the status the row
     * had before the claim - and writing it back on the next flush.
     */
    private function forgetCachedCopy(UuidValue $id): void
    {
        $managed = $this->em->getUnitOfWork()->tryGetById(['id' => $id->value], VideoTaskEntity::class);

        if ($managed instanceof VideoTaskEntity) {
            $this->em->refresh($managed);
        }
    }

    private function toDomain(VideoTaskEntity $e): VideoTask
    {
        return VideoTask::rehydrate(
            UuidValue::fromString($e->id),
            $e->payload,
            VideoTaskStatus::from($e->status),
            $e->finalVideoUrl,
            $e->errorMessage,
            DateTimeValue::fromDateTimeImmutable($e->createdAt),
            DateTimeValue::fromDateTimeImmutable($e->updatedAt),
            $e->callbackUrl,
        );
    }
}
