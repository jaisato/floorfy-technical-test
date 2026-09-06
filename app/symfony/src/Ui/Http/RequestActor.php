<?php

declare(strict_types=1);

namespace App\Ui\Http;

/**
 * Who is making the current request, as far as the API knows.
 *
 * Nothing authenticates a caller yet, so every request is anonymous. What
 * depends on the caller's identity - the scope of an Idempotency-Key, for one -
 * asks this class rather than assuming it, so that authentication, when it
 * arrives, has one place to plug into.
 */
final class RequestActor
{
    public const string ANONYMOUS = 'anonymous';

    /** A name for the caller that is never empty. */
    public function scope(): string
    {
        return self::ANONYMOUS;
    }
}
