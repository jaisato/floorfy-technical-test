<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

enum ClaimOutcome
{
    /** The key was free: the request may run and must store its response. */
    case CLAIMED;

    /** The same request was already answered: serve the stored response again. */
    case REPLAY;

    /** The key was used with a different request. */
    case MISMATCH;

    /** The original request is still running. */
    case IN_PROGRESS;
}
