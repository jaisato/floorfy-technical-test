<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

/**
 * How long a claim stands without a renewal before the task counts as
 * abandoned.
 *
 * The renewals happen between parts and never during one - a running ffmpeg is
 * left to finish its clip, which is what lets a retry reuse it - so the longest
 * a healthy attempt goes without touching the row is one ffmpeg run, and the
 * lease has to outlast that or the attempt declares itself abandoned. Shipped,
 * TASK_LEASE_SECONDS and FFMPEG_COMPOSE_TIMEOUT were the same hour: a
 * composition that used its whole budget expired the lease exactly as it
 * finished, and the next delivery of any message for that task re-rendered
 * everything on top of a worker that was still writing.
 *
 * So the configured value is a floor, not the answer: what the claim actually
 * gets is whichever is longer, and the operator's number decides how quickly a
 * genuinely dead worker's task comes back - which cannot be sooner than the
 * longest run they allow, because until then the two look identical from
 * outside.
 *
 * A figure rather than a service, and shared, because it answers the same
 * question in two places: the worker asks how long its claim is good for, and
 * the retention job asks how young a file has to be to possibly belong to a
 * render in progress. Two copies of one `max()` is how those two drift apart.
 */
final class TaskLease
{
    /**
     * Room between the longest step and the lease.
     *
     * The step's own timeout is when ffmpeg is killed, not when it is done, and
     * what happens after it - publishing, saving the row - takes a moment more.
     * A lease exactly as long as the step expires in that moment.
     */
    public const int SLACK_SECONDS = 300;

    public static function seconds(int $configured, int $animateTimeout, int $composeTimeout): int
    {
        return max($configured, max($animateTimeout, $composeTimeout) + self::SLACK_SECONDS);
    }
}
