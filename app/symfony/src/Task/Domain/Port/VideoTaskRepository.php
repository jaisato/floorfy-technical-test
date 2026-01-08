<?php
declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;

interface VideoTaskRepository
{
    public function save(VideoTask $task): void;

    public function get(UuidValue $id): ?VideoTask;
}
