<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marks an exception whose message is fit to leave the application.
 *
 * The message of anything else is an implementation detail - a stack of SQL, a
 * path on disk, the internals of a library - and is replaced by a generic one
 * before it reaches a client or a stored error field.
 */
interface ClientSafe extends \Throwable
{
}
