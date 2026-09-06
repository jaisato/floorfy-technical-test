<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

/**
 * One notification to deliver: where to, about which task and event, and the
 * task summary that goes in the body.
 */
final readonly class CallbackRequest
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public string $url,
        public string $taskId,
        public string $event,
        public array $body,
    ) {
    }
}
