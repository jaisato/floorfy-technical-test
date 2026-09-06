<?php

declare(strict_types=1);

namespace App\Task\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * The worker found, between two parts, that the task it is rendering was
 * canceled. Not a failure: the attempt stops, the message is acknowledged and
 * nothing is retried.
 */
final class TaskCanceled extends DomainException
{
    public static function noticed(string $taskId): self
    {
        return new self(\sprintf('La tarea "%s" fue cancelada mientras se procesaba.', $taskId));
    }
}
