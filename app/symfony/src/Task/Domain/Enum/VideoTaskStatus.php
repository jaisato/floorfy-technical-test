<?php

declare(strict_types=1);

namespace App\Task\Domain\Enum;

enum VideoTaskStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    /**
     * The statuses a task never leaves on its own: nothing is being rendered,
     * so its files can be deleted without breaking a run in progress.
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::COMPLETED, self::FAILED, self::CANCELED];
    }
}
