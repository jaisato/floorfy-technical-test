<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

use App\Shared\Domain\Bus\AsyncCommand;

/**
 * Tells the task's callback URL that the task reached this status.
 *
 * Carried by its own transport, with its own retries: a webhook endpoint that
 * is down for an hour must not hold up video work, and a video that fails must
 * not lose the notification of the previous one.
 */
final readonly class NotifyTaskCallback implements AsyncCommand
{
    public function __construct(
        public string $taskId,
        /** The status the task reached: completed, failed or canceled. */
        public string $event,
    ) {
    }
}
