<?php

declare(strict_types=1);

namespace App\Task\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * A command named a task that does not exist. Queries answer null for the
 * same situation; a command has nothing to return, so it says so.
 */
final class TaskNotFound extends DomainException
{
    public static function withId(string $taskId): self
    {
        return new self(\sprintf('No existe ninguna tarea con el identificador "%s".', $taskId));
    }
}
