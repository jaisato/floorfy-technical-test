<?php
declare(strict_types=1);

namespace App\Composition\Application\Command;

use App\Shared\Domain\Bus\AsyncCommand;

final readonly class ComposeFinalVideoCommand implements AsyncCommand
{
    public function __construct(public string $compositionId)
    {
    }
}
