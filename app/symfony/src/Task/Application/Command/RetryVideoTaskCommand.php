<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\Bus\Command;

final readonly class RetryVideoTaskCommand implements Command
{
    public function __construct(public string $taskId)
    {
    }
}
