<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\ReadModel;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\DTO\VideoTaskSummaryView;
use App\Task\Application\ReadModel\TaskListing;
use App\Task\Application\ReadModel\TaskPage;
use App\Task\Application\ReadModel\VideoTaskReadRepository;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\ValueObject\TaskProgress;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Task listings straight from SQL.
 *
 * Two statements per page, whatever its size: the page of task rows, then the
 * part counts of exactly those tasks grouped by status. Joining and grouping in
 * one statement would make the database aggregate the parts of every matching
 * task before it could apply the LIMIT.
 */
final readonly class DbalVideoTaskReadRepository implements VideoTaskReadRepository
{
    public function __construct(
        private Connection $connection,
        private VideoUrls $urls,
    ) {
    }

    public function list(TaskListing $listing): TaskPage
    {
        $total = $this->count($listing);

        if (0 === $total || $listing->offset() >= $total) {
            return new TaskPage([], $total, $listing->page, $listing->limit);
        }

        $rows = $this->pageRows($listing);
        $counts = $this->partCounts(array_map(static fn (array $row): string => self::string($row, 'id'), $rows));

        $items = [];
        foreach ($rows as $row) {
            $id = self::string($row, 'id');
            $byStatus = $counts[$id] ?? [];

            $items[] = new VideoTaskSummaryView(
                $id,
                self::string($row, 'status'),
                TaskProgress::ofCounts(
                    $byStatus[PartialVideoStatus::COMPLETED->value] ?? 0,
                    $byStatus[PartialVideoStatus::FAILED->value] ?? 0,
                    $byStatus[PartialVideoStatus::PENDING->value] ?? 0,
                ),
                $this->urls->absolute(self::nullableString($row, 'final_video_url')),
                self::nullableString($row, 'error_message'),
                self::nullableString($row, 'callback_url'),
                self::instant($row, 'created_at'),
                self::instant($row, 'updated_at'),
                null === ($row['pruned_at'] ?? null) ? null : self::instant($row, 'pruned_at'),
            );
        }

        return new TaskPage($items, $total, $listing->page, $listing->limit);
    }

    private function count(TaskListing $listing): int
    {
        $query = $this->filtered($listing)->select('COUNT(*)');

        return self::int($query->fetchOne());
    }

    /** @return array<int, array<string, mixed>> */
    private function pageRows(TaskListing $listing): array
    {
        $query = $this->filtered($listing)
            ->select('t.id', 't.status', 't.final_video_url', 't.error_message', 't.callback_url', 't.created_at', 't.updated_at', 't.pruned_at')
            // Newest first, and the id - time-ordered, being a UUID v7 - breaks
            // ties among tasks created in the same second, so two pages never
            // overlap. Both descending, so the (created_at, id) index is read
            // backwards rather than sorted.
            ->orderBy('t.created_at', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($listing->offset())
            ->setMaxResults($listing->limit);

        return $query->fetchAllAssociative();
    }

    private function filtered(TaskListing $listing): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()->from('video_tasks', 't');

        if (null !== $listing->status) {
            $query->andWhere('t.status = :status')
                ->setParameter('status', $listing->status->value, ParameterType::STRING);
        }

        if (null !== $listing->createdFrom) {
            $query->andWhere('t.created_at >= :created_from')
                ->setParameter('created_from', $listing->createdFrom->toDateTimeImmutable(), Types::DATETIME_IMMUTABLE);
        }

        if (null !== $listing->createdTo) {
            $query->andWhere('t.created_at <= :created_to')
                ->setParameter('created_to', $listing->createdTo->toDateTimeImmutable(), Types::DATETIME_IMMUTABLE);
        }

        return $query;
    }

    /**
     * @param array<int, string> $taskIds
     *
     * @return array<string, array<string, int>> task id => part status => count
     */
    private function partCounts(array $taskIds): array
    {
        if ([] === $taskIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT task_id, status, COUNT(*) AS parts FROM partial_videos WHERE task_id IN (:ids) GROUP BY task_id, status',
            ['ids' => $taskIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[self::string($row, 'task_id')][self::string($row, 'status')] = self::int($row['parts'] ?? null);
        }

        return $counts;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('La columna "%s" no contiene texto.', $column));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $column): ?string
    {
        return null === ($row[$column] ?? null) ? null : self::string($row, $column);
    }

    /**
     * DATETIME columns carry no zone and are written in UTC; reading them back
     * as text, explicitly as UTC, does not depend on the process's default zone
     * the way hydrating through the driver would.
     *
     * @param array<string, mixed> $row
     */
    private static function instant(array $row, string $column): string
    {
        return DateTimeValue::fromString(self::string($row, $column))->toIso8601();
    }

    /** Drivers answer COUNT(*) as an int or as a numeric string, depending on the platform. */
    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('La base de datos devolvió un recuento que no es un entero.');
    }
}
