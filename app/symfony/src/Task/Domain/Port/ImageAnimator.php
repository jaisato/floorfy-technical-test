<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Task\Domain\Enum\Transition;
use App\Task\Domain\ValueObject\RenderOptions;

interface ImageAnimator
{
    /**
     * Turns one still image into a short clip using the given camera move.
     *
     * The options carry the clip's length, frame rate and size. They belong in
     * the call rather than in the adapter's constructor because they are the
     * task's, not the deployment's: two tasks running through the same worker
     * can ask for different ones.
     *
     * @throws \RuntimeException when the clip cannot be produced
     */
    public function animate(string $imageFile, Transition $transition, string $outputFile, RenderOptions $options): void;
}
