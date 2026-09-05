<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;

interface PartialVideoRepository
{
    public function save(PartialVideo $partial): void;

    /**
     * Writes a whole batch in one round trip.
     *
     * @param list<PartialVideo> $partials
     */
    public function saveAll(array $partials): void;

    /**
     * The parts of a task, in the order they must be concatenated.
     *
     * @return list<PartialVideo>
     */
    public function listByTaskId(UuidValue $taskId): array;
}
