<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\ValueObject\UuidValue;

/**
 * Which attempt this worker is on, for the one thing that runs after the
 * handler has already given up.
 *
 * `MarkTaskFailedWhenRetriesAreExhausted` is a listener: it runs once the
 * handler has thrown and the transport has decided there are no retries left.
 * By then the handler has handed the claim back, and the row it would read is
 * no longer a record of the run that failed - a duplicate delivery can have
 * claimed the task in the microseconds in between, and marking "whatever is
 * processing now" as failed marks a healthy run failed, with a callback to
 * match.
 *
 * A Messenger worker handles one message at a time, so "the attempt that just
 * failed" is a well-defined thing to hold for the length of one delivery. The
 * handler writes it; the listener reads it and conditions its write on it.
 *
 * When nothing was recorded - the handler never claimed the task, or never ran
 * at all because the worker died or the message would not deserialise - the
 * listener has no attempt to name and falls back to the unconditional
 * transition, which is the recovery this listener was written for.
 */
final class AttemptInFlight
{
    private ?string $taskId = null;

    private ?int $generation = null;

    /** Forgets whatever the previous delivery left behind. */
    public function none(): void
    {
        $this->taskId = null;
        $this->generation = null;
    }

    /**
     * Records the attempt this worker holds, by the generation it will have
     * handed back if it fails.
     *
     * @param int|null $generation null when the claim was already gone, so
     *                             there is nothing of this attempt's to fail
     */
    public function released(UuidValue $taskId, ?int $generation): void
    {
        $this->taskId = null === $generation ? null : $taskId->value;
        $this->generation = $generation;
    }

    /** The generation this worker handed back for that task, if it was this one. */
    public function generationOf(UuidValue $taskId): ?int
    {
        return $this->taskId === $taskId->value ? $this->generation : null;
    }
}
