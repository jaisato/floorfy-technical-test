<?php
declare(strict_types=1);

namespace App\Composition\Application\Command;

use App\Shared\Domain\Bus\Command;

final readonly class CreateCompositionCommand implements Command
{
    /** @param list<string> $animationVideoUrls */
    public function __construct(public array $animationVideoUrls)
    {
    }
}
