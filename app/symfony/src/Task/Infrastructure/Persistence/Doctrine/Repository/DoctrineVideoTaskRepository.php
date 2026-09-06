<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Repository;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Task\Infrastructure\Persistence\Doctrine\Entity\VideoTaskEntity;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

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
            $entity->renderOptions = $task->renderOptions()?->toArray();
            $entity->createdAt = $task->createdAt()->toDateTimeImmutable();
            $this->em->persist($entity);
        }

        $entity->status = $task->status()->value;
        $entity->finalVideoUrl = $task->finalVideoUrl();
        $entity->errorMessage = $task->errorMessage();
        $entity->updatedAt = $task->updatedAt()->toDateTimeImmutable();
        $entity->prunedAt = $task->prunedAt()?->toDateTimeImmutable();

        $this->em->flush();
    }

    public function get(UuidValue $id): ?VideoTask
    {
        $entity = $this->em->find(VideoTaskEntity::class, $id->value);

        return $entity instanceof VideoTaskEntity ? $this->toDomain($entity) : null;
    }

    public function getForUpdate(UuidValue $id): ?VideoTask
    {
        // HINT_REFRESH is what makes this a read rather than a formality.
        // Without it the query takes the lock in the database and then hands
        // back whatever copy the unit of work is already holding - and the one
        // caller of this has just listed the task, so it always is holding one.
        // The row would be locked and the state read from it minutes old,
        // which is the precise failure the lock is here to prevent.
        $entity = $this->em->createQueryBuilder()
            ->select('t')
            ->from(VideoTaskEntity::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $id->value)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof VideoTaskEntity ? $this->toDomain($entity) : null;
    }

    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): bool
    {
        // One statement, so the database arbitrates. Read-then-write would let
        // two workers both see "pending" and both start.
        //
        // The three nulls are what VideoTask::markProcessing() writes, and the
        // reason this statement cannot just set the status: a run starting
        // carries none of the previous one's outcome. prunedAt is the one that
        // does damage left behind - a task replayed from the failure transport
        // after the retention sweep had reclaimed its files rendered a new
        // video while still telling clients its files were gone, and prunedAt
        // is exactly what excludes a row from the sweep, so nothing would ever
        // clean the new ones up.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :processing,
                       updated_at = :now,
                       error_message = NULL,
                       final_video_url = NULL,
                       pruned_at = NULL
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

    public function renewLease(UuidValue $id, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET updated_at = :now
                 WHERE id = :id AND status = :processing
                SQL,
            [
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
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

    public function complete(UuidValue $id, string $finalVideoUrl, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :completed, final_video_url = :url, error_message = NULL, updated_at = :now
                 WHERE id = :id AND status = :processing
                SQL,
            [
                'completed' => VideoTaskStatus::COMPLETED->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'url' => $finalVideoUrl,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'completed' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'url' => ParameterType::STRING,
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

    public function markCallbackNotified(UuidValue $id, string $event, DateTimeValue $settledAt, DateTimeValue $now): bool
    {
        // Conditional on the task still standing where the notification said
        // it did: a retry that landed while the delivery was in flight has
        // moved it on, and this mark would answer for a run whose own
        // notification never went out.
        //
        // The status alone does not say that. It is reusable: run A ends
        // failed, its delivery is slow, a retry starts run B and it ends
        // failed too - and A's mark was accepted for B, so when B's own
        // notification was lost the sweep saw callback_notified_at set and
        // never offered the task again. updated_at is what tells the two runs
        // apart: every terminal transition writes it, and neither this mark
        // nor the sweep's claim touches it. (DATETIME, so one second is the
        // resolution; two runs of the same task settling inside one second is
        // not a thing a render does.)
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE video_tasks SET callback_notified_at = :now WHERE id = :id AND status = :event AND updated_at = :settled',
            ['now' => $now->toDateTimeImmutable(), 'id' => $id->value, 'event' => $event, 'settled' => $settledAt->toDateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::STRING, 'event' => ParameterType::STRING, 'settled' => Types::DATETIME_IMMUTABLE],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected;
    }

    public function markCallbackAbandoned(UuidValue $id, string $event, DateTimeValue $settledAt, DateTimeValue $now): bool
    {
        // The same fence as the mark above, and for the same reason: a task
        // that settled again while this delivery was being refused owes a
        // fresh notification, and this verdict was reached about the previous
        // run. Written unconditionally it would silence the sweep for a run
        // whose own notification never went out.
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE video_tasks SET callback_abandoned_at = :now WHERE id = :id AND status = :event AND updated_at = :settled',
            ['now' => $now->toDateTimeImmutable(), 'id' => $id->value, 'event' => $event, 'settled' => $settledAt->toDateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::STRING, 'event' => ParameterType::STRING, 'settled' => Types::DATETIME_IMMUTABLE],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected;
    }

    public function clearCallbackNotification(UuidValue $id): void
    {
        // The attempt goes with it: a run queued again owes a fresh
        // notification, and the stamp of the previous run's sweep would hold
        // that one back for a whole cutoff. So does the verdict: the URL is
        // checked again on the next delivery and the signing secret may have
        // been configured since, so a new run is not refused for what the
        // previous one ran into.
        $this->em->getConnection()->executeStatement(
            'UPDATE video_tasks SET callback_notified_at = NULL, callback_attempted_at = NULL, callback_abandoned_at = NULL WHERE id = :id',
            ['id' => $id->value],
            ['id' => ParameterType::STRING],
        );

        $this->forgetCachedCopy($id);
    }

    public function claimRepublication(UuidValue $id, DateTimeValue $before, DateTimeValue $now): bool
    {
        // Conditional, like every other claim here: two sweeps running at once
        // both read the task as unclaimed, and only the one whose UPDATE lands
        // publishes. The condition repeats what the listing asked, so a task a
        // worker picked up in between is not claimed at all.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET updated_at = :now
                 WHERE id = :id AND status = :pending AND updated_at < :before
                SQL,
            [
                'now' => $now->toDateTimeImmutable(),
                'before' => $before->toDateTimeImmutable(),
                'pending' => VideoTaskStatus::PENDING->value,
                'id' => $id->value,
            ],
            [
                'now' => Types::DATETIME_IMMUTABLE,
                'before' => Types::DATETIME_IMMUTABLE,
                'pending' => ParameterType::STRING,
                'id' => ParameterType::STRING,
            ],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected;
    }

    public function claimCallbackNotification(UuidValue $id, DateTimeValue $before, DateTimeValue $now): bool
    {
        // Conditional, like the claim on a task: two sweeps running at once
        // both read the row as owed, and only the one whose UPDATE lands gets
        // to publish. The condition repeats what the listing asked so a
        // notification delivered in between is not claimed at all.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET callback_attempted_at = :now
                 WHERE id = :id
                   AND callback_notified_at IS NULL
                   AND callback_abandoned_at IS NULL
                   AND (callback_attempted_at IS NULL OR callback_attempted_at < :before)
                SQL,
            ['now' => $now->toDateTimeImmutable(), 'before' => $before->toDateTimeImmutable(), 'id' => $id->value],
            ['now' => Types::DATETIME_IMMUTABLE, 'before' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::STRING],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected;
    }

    public function markFailedIfStillRunning(UuidValue $id, string $errorMessage, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :failed, error_message = :error, updated_at = :now
                 WHERE id = :id AND (status = :pending OR status = :processing)
                SQL,
            [
                'failed' => VideoTaskStatus::FAILED->value,
                'error' => $errorMessage,
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'failed' => ParameterType::STRING,
                'error' => ParameterType::STRING,
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

    public function listPrunable(DateTimeValue $before, int $limit): array
    {
        $entities = $this->em->createQueryBuilder()
            ->select('t')
            ->from(VideoTaskEntity::class, 't')
            ->where('t.prunedAt IS NULL')
            ->andWhere('t.updatedAt < :before')
            ->andWhere('t.status IN (:settled)')
            ->orderBy('t.updatedAt', 'ASC')
            ->setParameter('before', $before->toDateTimeImmutable())
            ->setParameter('settled', array_map(
                static fn (VideoTaskStatus $status): string => $status->value,
                VideoTaskStatus::settled(),
            ))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $tasks = [];

        foreach ($entities as $entity) {
            if ($entity instanceof VideoTaskEntity) {
                $tasks[] = $this->toDomain($entity);
            }
        }

        return $tasks;
    }

    public function listUnclaimedSince(DateTimeValue $before, int $limit): array
    {
        return $this->hydrate(
            $this->em->createQueryBuilder()
                ->select('t')
                ->from(VideoTaskEntity::class, 't')
                ->where('t.status = :pending')
                ->andWhere('t.updatedAt < :before')
                ->orderBy('t.updatedAt', 'ASC')
                ->setParameter('pending', VideoTaskStatus::PENDING->value)
                ->setParameter('before', $before->toDateTimeImmutable())
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult(),
        );
    }

    public function listAwaitingCallback(DateTimeValue $before, int $limit): array
    {
        return $this->hydrate(
            $this->em->createQueryBuilder()
                ->select('t')
                ->from(VideoTaskEntity::class, 't')
                ->where('t.callbackUrl IS NOT NULL')
                ->andWhere('t.callbackNotifiedAt IS NULL')
                // A notification nothing can deliver - a URL the guard refuses,
                // a deployment with no signing secret - is not one that was
                // lost. Offered again it is refused again, so the sweep would
                // republish the same doomed message for the life of the task.
                ->andWhere('t.callbackAbandonedAt IS NULL')
                ->andWhere('t.updatedAt < :before')
                // A notification this sweep already published is not lost yet:
                // it waits out the same cutoff before being offered again, so a
                // delivery the transport is still retrying is not published
                // afresh by every run in the meantime.
                ->andWhere('t.callbackAttemptedAt IS NULL OR t.callbackAttemptedAt < :before')
                ->andWhere('t.status IN (:settled)')
                ->orderBy('t.updatedAt', 'ASC')
                ->setParameter('before', $before->toDateTimeImmutable())
                ->setParameter('settled', array_map(
                    static fn (VideoTaskStatus $status): string => $status->value,
                    VideoTaskStatus::settled(),
                ))
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @param mixed $rows whatever the query returned
     *
     * @return list<VideoTask>
     */
    private function hydrate(mixed $rows): array
    {
        $tasks = [];

        foreach (is_iterable($rows) ? $rows : [] as $entity) {
            if ($entity instanceof VideoTaskEntity) {
                $tasks[] = $this->toDomain($entity);
            }
        }

        return $tasks;
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
            null === $e->renderOptions ? null : RenderOptions::fromArray($e->renderOptions),
            null === $e->prunedAt ? null : DateTimeValue::fromDateTimeImmutable($e->prunedAt),
        );
    }
}
