<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Task\Domain\Enum\Transition;

interface ImageAnimator
{
    /**
     * Turns one still image into a short clip using the given camera move.
     *
     * @throws \RuntimeException when the clip cannot be produced
     */
    public function animate(string $imageFile, Transition $transition, string $outputFile): void;
}
