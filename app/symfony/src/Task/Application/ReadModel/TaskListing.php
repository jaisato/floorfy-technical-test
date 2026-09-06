<?php

declare(strict_types=1);

namespace App\Task\Application\ReadModel;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Domain\Enum\VideoTaskStatus;

/**
 * What a listing of tasks is filtered and paged by.
 *
 * Built by the UI layer once the request has been validated, so a repository
 * only ever sees a status that exists, dates that parse and a page size inside
 * the ceiling; the request strings never reach SQL.
 */
final readonly class TaskListing
{
    public const int DEFAULT_LIMIT = 20;

    /**
     * One page is one round trip plus a part count per task; a client that
     * wants everything pages through it.
     */
    public const int MAX_LIMIT = 100;

    public function __construct(
        public ?VideoTaskStatus $status = null,
        /** Inclusive lower bound on created_at. */
        public ?DateTimeValue $createdFrom = null,
        /** Inclusive upper bound on created_at. */
        public ?DateTimeValue $createdTo = null,
        public int $page = 1,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('La página debe ser 1 o mayor.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('El tamaño de página debe estar entre 1 y %d.', self::MAX_LIMIT));
        }

        if (null !== $createdFrom && null !== $createdTo
            && $createdFrom->toDateTimeImmutable() > $createdTo->toDateTimeImmutable()) {
            throw new \InvalidArgumentException('El inicio del rango de fechas es posterior a su fin.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
