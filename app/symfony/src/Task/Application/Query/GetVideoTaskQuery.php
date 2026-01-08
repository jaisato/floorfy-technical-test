<?php
declare(strict_types=1);

namespace App\Task\Application\Query;

use App\Shared\Domain\Bus\Query;

final readonly class GetVideoTaskQuery implements Query
{
    public function __construct(public string $taskId)
    {
    }
}
