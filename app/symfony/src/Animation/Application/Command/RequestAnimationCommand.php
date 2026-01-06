<?php
declare(strict_types=1);

namespace App\Animation\Application\Command;

use App\Shared\Domain\Bus\Command;

final readonly class RequestAnimationCommand implements Command
{
    public function __construct(public string $imageUrl, public string $prompt)
    {
    }
}
