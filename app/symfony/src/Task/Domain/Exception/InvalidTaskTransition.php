<?php

declare(strict_types=1);

namespace App\Task\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;
use App\Task\Domain\Enum\VideoTaskStatus;

final class InvalidTaskTransition extends DomainException
{
    public static function between(VideoTaskStatus $from, VideoTaskStatus $to): self
    {
        return new self(\sprintf('Transición de estado no permitida: "%s" -> "%s".', $from->value, $to->value));
    }
}
