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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class DoctrineVideoTaskRepository implements VideoTaskRepository
{
    /**
     * How many times a transition re-reads the generation and tries its
     * compare again before giving up.
     *
     * Each pass is only spent on a racer that got its write in first, and it
     * leaves the row where this transition's own status conditions decide:
     * either they now refuse it, which ends the loop on the next pass, or they
     * still allow it and one more compare settles it. Three is a bound, not an
     * expectation - the second pass is already the unlikely one.
     */
    private const int TRANSITION_ATTEMPTS = 3;

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

    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): ?int
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
        //
        // run_generation is what the claim hands the worker. Everything it
        // writes afterwards is conditional on it, so an attempt that was taken
        // over cannot renew, release or complete a claim that is no longer
        // its own.
        return $this->transitionWithGeneration(
            $id,
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :processing,
                       updated_at = :now,
                       error_message = NULL,
                       final_video_url = NULL,
                       pruned_at = NULL,
                       run_generation = :next
                 WHERE id = :id
                   AND run_generation = :current
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
            ],
            [
                'processing' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'failed' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'stale' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function release(UuidValue $id, int $generation, DateTimeValue $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :pending, updated_at = :now, run_generation = :next
                 WHERE id = :id AND status = :processing AND run_generation = :generation
                SQL,
            [
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'generation' => $generation,
                'next' => $generation + 1,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'generation' => ParameterType::INTEGER,
                'next' => ParameterType::INTEGER,
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

    public function renewLease(UuidValue $id, int $generation, DateTimeValue $now): bool
    {
        // No bump: a renewal says the same attempt is still running, so the
        // number it is holding has to survive it.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET updated_at = :now
                 WHERE id = :id AND status = :processing AND run_generation = :generation
                SQL,
            [
                'processing' => VideoTaskStatus::PROCESSING->value,
                'generation' => $generation,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'processing' => ParameterType::STRING,
                'generation' => ParameterType::INTEGER,
                'now' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
            ],
        );

        // Zero rows is not "the claim is gone" here, and this is the one
        // statement where the difference bites: MySQL counts rows it *changed*,
        // and `updated_at` is a DATETIME. A renewal in the same second as the
        // claim - or as the previous one, which is every part that finishes
        // quickly or is reused from an earlier run - writes the value the
        // column already holds and changes nothing. The attempt then read that
        // as having lost the task and abandoned a run that was perfectly
        // healthy. (SQLite counts matched rows, so the test profile never saw
        // it; the MySQL job did, the first time a test renewed twice on one
        // clock.)
        $stillOurs = $affected >= 1 || $this->rowMatches(
            $id,
            'status = :processing AND run_generation = :generation',
            ['processing' => VideoTaskStatus::PROCESSING->value, 'generation' => $generation],
            ['processing' => ParameterType::STRING, 'generation' => ParameterType::INTEGER],
        );

        if (!$stillOurs) {
            return false;
        }

        $this->forgetCachedCopy($id);

        return true;
    }

    public function complete(UuidValue $id, string $finalVideoUrl, int $generation, DateTimeValue $now): ?int
    {
        // The one transition whose new number needs no read: the caller is
        // holding the claim this completes, so the number after it is that
        // claim's plus one, and the compare in the WHERE is what proves it.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :completed,
                       final_video_url = :url,
                       error_message = NULL,
                       updated_at = :now,
                       run_generation = :next
                 WHERE id = :id AND status = :processing AND run_generation = :generation
                SQL,
            [
                'completed' => VideoTaskStatus::COMPLETED->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'generation' => $generation,
                'next' => $generation + 1,
                'url' => $finalVideoUrl,
                'now' => $now->toDateTimeImmutable(),
                'id' => $id->value,
            ],
            [
                'completed' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'generation' => ParameterType::INTEGER,
                'next' => ParameterType::INTEGER,
                'url' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
            ],
        );

        if ($affected < 1) {
            return null;
        }

        $this->forgetCachedCopy($id);

        return $generation + 1;
    }

    public function markCallbackNotified(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool
    {
        // Conditional on the task still standing where the notification said
        // it did: a retry that landed while the delivery was in flight has
        // moved it on, and this mark would answer for a run whose own
        // notification never went out.
        //
        // The generation is what says so. The status is reusable - run A ends
        // failed, its delivery is slow, a retry starts run B and it ends
        // failed too, and A's mark was accepted for B - and so, in practice,
        // was updated_at, which tried to stand in for the run before this
        // column existed: MySQL DATETIME has one second of resolution, and
        // cancel, retry, cancel with no worker in between settles two runs
        // inside it. Every transition that starts or ends a run moves the
        // generation on, so the number a notification carries names one of
        // them and is never seen twice.
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE video_tasks SET callback_notified_at = :now WHERE id = :id AND status = :event AND run_generation = :generation',
            ['now' => $now->toDateTimeImmutable(), 'id' => $id->value, 'event' => $event, 'generation' => $generation],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::STRING, 'event' => ParameterType::STRING, 'generation' => ParameterType::INTEGER],
        );

        $this->forgetCachedCopy($id);

        // As in renewLease(): MySQL counts changed rows, so a mark written a
        // second time with the same instant changes nothing and would be
        // reported as belonging to another run.
        return 1 === $affected || $this->marksThisRun($id, $event, $generation);
    }

    public function markCallbackAbandoned(UuidValue $id, string $event, int $generation, DateTimeValue $now): bool
    {
        // The same fence as the mark above, and for the same reason: a task
        // that settled again while this delivery was being refused owes a
        // fresh notification, and this verdict was reached about the previous
        // run. Written unconditionally it would silence the sweep for a run
        // whose own notification never went out.
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE video_tasks SET callback_abandoned_at = :now WHERE id = :id AND status = :event AND run_generation = :generation',
            ['now' => $now->toDateTimeImmutable(), 'id' => $id->value, 'event' => $event, 'generation' => $generation],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::STRING, 'event' => ParameterType::STRING, 'generation' => ParameterType::INTEGER],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected || $this->marksThisRun($id, $event, $generation);
    }

    /** Whether the row is still the run a callback mark was written about. */
    private function marksThisRun(UuidValue $id, string $event, int $generation): bool
    {
        return $this->rowMatches(
            $id,
            'status = :event AND run_generation = :generation',
            ['event' => $event, 'generation' => $generation],
            ['event' => ParameterType::STRING, 'generation' => ParameterType::INTEGER],
        );
    }

    /**
     * Whether the row still satisfies a condition an UPDATE reported no rows
     * for.
     *
     * MySQL's affected-row count is rows *changed*, not rows matched, so a
     * statement that writes a column the value it already holds reports zero -
     * indistinguishable, from the count alone, from a condition that matched
     * nothing. Everything here reads that count as "did the WHERE match", so
     * where a statement can legitimately write an unchanged value the question
     * is asked again, directly.
     *
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    private function rowMatches(UuidValue $id, string $condition, array $params, array $types): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM video_tasks WHERE id = :id AND '.$condition,
            [...$params, 'id' => $id->value],
            [...$types, 'id' => ParameterType::STRING],
        );
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

    public function claimCallbackNotification(UuidValue $id, int $generation, DateTimeValue $before, DateTimeValue $now): bool
    {
        // Conditional, like the claim on a task: two sweeps running at once
        // both read the row as owed, and only the one whose UPDATE lands gets
        // to publish. The condition repeats the listing's - every clause of
        // it, and that is the point rather than tidiness. The three callback
        // columns alone were satisfied by a row a retry had just queued again:
        // retrying clears all three, so the claim landed on a task that was
        // pending once more, and the sweep published the *listed* object's
        // terminal status while the handler built the body from the run now
        // under way. The client was told the task had failed as it started
        // over. Status, cutoff and "asked for a callback at all" are what make
        // a concurrent retry lose the claim.
        //
        // And the generation, which is the run the listing read: a task that
        // was retried and settled again in between satisfies every clause
        // above once more, and the notification this sweep is about to publish
        // is the previous run's.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE video_tasks
                   SET callback_attempted_at = :now
                 WHERE id = :id
                   AND run_generation = :generation
                   AND callback_url IS NOT NULL
                   AND callback_notified_at IS NULL
                   AND callback_abandoned_at IS NULL
                   AND updated_at < :before
                   AND status IN (:settled)
                   AND (callback_attempted_at IS NULL OR callback_attempted_at < :before)
                SQL,
            [
                'now' => $now->toDateTimeImmutable(),
                'before' => $before->toDateTimeImmutable(),
                'id' => $id->value,
                'generation' => $generation,
                'settled' => array_map(
                    static fn (VideoTaskStatus $status): string => $status->value,
                    VideoTaskStatus::settled(),
                ),
            ],
            [
                'now' => Types::DATETIME_IMMUTABLE,
                'before' => Types::DATETIME_IMMUTABLE,
                'id' => ParameterType::STRING,
                'generation' => ParameterType::INTEGER,
                'settled' => ArrayParameterType::STRING,
            ],
        );

        $this->forgetCachedCopy($id);

        return 1 === $affected;
    }

    public function markFailedIfStillRunning(UuidValue $id, string $errorMessage, DateTimeValue $now): ?int
    {
        return $this->transitionWithGeneration(
            $id,
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :failed, error_message = :error, updated_at = :now, run_generation = :next
                 WHERE id = :id
                   AND run_generation = :current
                   AND (status = :pending OR status = :processing)
                SQL,
            [
                'failed' => VideoTaskStatus::FAILED->value,
                'error' => $errorMessage,
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
            ],
            [
                'failed' => ParameterType::STRING,
                'error' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function cancel(UuidValue $id, DateTimeValue $now): ?int
    {
        return $this->transitionWithGeneration(
            $id,
            <<<'SQL'
                UPDATE video_tasks
                   SET status = :canceled, updated_at = :now, run_generation = :next
                 WHERE id = :id
                   AND run_generation = :current
                   AND (status = :pending OR status = :processing)
                SQL,
            [
                'canceled' => VideoTaskStatus::CANCELED->value,
                'pending' => VideoTaskStatus::PENDING->value,
                'processing' => VideoTaskStatus::PROCESSING->value,
                'now' => $now->toDateTimeImmutable(),
            ],
            [
                'canceled' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    /**
     * Runs a transition that has to report the generation it produced.
     *
     * The number cannot be read back after the UPDATE: a claim can take a
     * task the moment it lands on `failed`, and the value read a moment later
     * would then be that new run's - handed to the caller as its own. So the
     * current number is read first and written into the compare, and the new
     * one is that plus one: whatever the statement changes, the number
     * returned is the one this transition wrote and no other.
     *
     * A failed compare is either the row's own conditions refusing the
     * transition or somebody else's write landing in between, and only the
     * second is worth another pass. Reading the generation again tells them
     * apart, and each pass narrows the field: a racer that got in first has
     * left the row where the status conditions decide afresh.
     *
     * @param array<string, mixed>                $params the statement's own parameters; `id`, `current` and `next` are added here
     * @param array<string, ParameterType|string> $types  their types, likewise
     */
    private function transitionWithGeneration(UuidValue $id, string $sql, array $params, array $types): ?int
    {
        for ($attempt = 0; $attempt < self::TRANSITION_ATTEMPTS; ++$attempt) {
            $current = $this->generationOf($id);

            if (null === $current) {
                return null;
            }

            $next = $current + 1;

            $affected = $this->em->getConnection()->executeStatement(
                $sql,
                [...$params, 'id' => $id->value, 'current' => $current, 'next' => $next],
                [...$types, 'id' => ParameterType::STRING, 'current' => ParameterType::INTEGER, 'next' => ParameterType::INTEGER],
            );

            if ($affected >= 1) {
                $this->forgetCachedCopy($id);

                return $next;
            }

            if ($this->generationOf($id) === $current) {
                // The row has not moved, so it was the transition's own
                // conditions that refused it. Trying again would only ask the
                // same question of the same row.
                return null;
            }
        }

        return null;
    }

    /** The generation the row carries right now, read past any loaded copy. */
    private function generationOf(UuidValue $id): ?int
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT run_generation FROM video_tasks WHERE id = :id',
            ['id' => $id->value],
            ['id' => ParameterType::STRING],
        );

        return is_numeric($value) ? (int) $value : null;
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
            $e->runGeneration,
        );
    }
}
