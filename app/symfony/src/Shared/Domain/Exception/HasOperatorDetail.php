<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marks an exception carrying detail that belongs in the log and nowhere else -
 * a command line, a tool's stderr.
 */
interface HasOperatorDetail extends \Throwable
{
    /** @return array<string, string> */
    public function operatorDetail(): array;
}
