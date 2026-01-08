<?php
declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\Bus\AsyncCommand;

final readonly class ProcessVideoTaskCommand implements AsyncCommand
{
    public function __construct(public string $taskId) {}
}
