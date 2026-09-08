<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

/**
 * One notification to deliver: where to, about which task, event and run, and
 * the task summary that goes in the body.
 */
final readonly class CallbackRequest
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public string $url,
        public string $taskId,
        public string $event,
        /**
         * Which run of the task this notification is about.
         *
         * Travels to the receiver, and is signed with the rest: the body says
         * how the task stands *now*, so a client that receives two
         * notifications for one task has nothing else to order them by - two
         * runs can both end `failed`, and the second they settled in is the
         * same when a cancel, a retry and a cancel land together.
         */
        public int $generation,
        public array $body,
    ) {
    }
}
