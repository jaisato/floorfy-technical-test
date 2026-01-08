<?php
declare(strict_types=1);

namespace App\Task\Domain\Enum;

enum PartialVideoStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
