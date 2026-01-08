<?php
declare(strict_types=1);

namespace App\Task\Domain\Enum;

enum VideoTaskStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
