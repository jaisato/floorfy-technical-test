<?php
declare(strict_types=1);

namespace App\Animation\Application\Command;

use App\Shared\Domain\Bus\AsyncCommand;

final readonly class TrackAnimationStatusCommand implements AsyncCommand
{
    public function __construct(public string $operationId)
    {
    }
}
