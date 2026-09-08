<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marks a failure that doing the work again cannot fix.
 *
 * A transport retries because most failures are about a moment: a broker that
 * was down, a host that timed out, a disk that was full. Some are not. A URL
 * the policy refuses is refused for what it is - its scheme, its port, the
 * address its host resolves to, the media type it serves - and the twentieth
 * attempt reaches the same conclusion as the first, three hours later, having
 * held a worker for every one of them.
 *
 * A handler that sees only this kind of failure says so with
 * UnrecoverableMessageHandlingException, and the message goes straight to the
 * failure transport with the answer it already had.
 */
interface PermanentFailure extends \Throwable
{
}
