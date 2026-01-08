<?php
declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;

interface PartialVideoRepository
{
    public function save(PartialVideo $partial): void;

    /** @return list<PartialVideo> */
    public function listByTaskId(UuidValue $taskId): array;
}
