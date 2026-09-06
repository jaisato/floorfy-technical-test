<?php

declare(strict_types=1);

namespace App\Task\Domain\ValueObject;

/**
 * One rendered part, ready to be concatenated: where the file is and how long
 * it runs.
 *
 * The length travels with the file because the crossfade needs it: the offset
 * at which each fade starts is the sum of everything before it, and a composer
 * that only had paths would have to ask ffprobe for something the application
 * already knows.
 */
final readonly class Clip
{
    public function __construct(
        public string $file,
        public float $durationSeconds,
    ) {
    }
}
