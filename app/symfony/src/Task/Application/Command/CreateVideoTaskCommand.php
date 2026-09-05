<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\Bus\Command;

final readonly class CreateVideoTaskCommand implements Command
{
    /**
     * @param list<array{url: string, transition: string}> $images already
     *                                                             validated and normalised by the UI layer, in playback order
     */
    public function __construct(public array $images)
    {
    }
}
