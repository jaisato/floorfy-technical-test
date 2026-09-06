<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\Bus\Command;

final readonly class CreateVideoTaskCommand implements Command
{
    /**
     * @param list<array{url: string, transition: string}> $images      already
     *                                                                  validated and normalised by the UI layer, in playback order
     * @param string|null                                  $callbackUrl where to POST the outcome; validated by the UI layer against
     *                                                                  the same rules as the image URLs
     */
    public function __construct(
        public array $images,
        public ?string $callbackUrl = null,
    ) {
    }
}
