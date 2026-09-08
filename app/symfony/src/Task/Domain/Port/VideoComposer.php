<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;

interface VideoComposer
{
    /**
     * Concatenates clips this application produced into one file.
     *
     * Only local paths are accepted. An earlier revision also took URLs and
     * fetched them, which put a second, unguarded outbound-request path in a
     * component that never needs one; parts are always files on this disk.
     *
     * @param list<Clip> $clips in playback order, with their lengths
     *
     * @throws \RuntimeException when the parts cannot be concatenated
     */
    public function compose(array $clips, string $outputFile, RenderOptions $options): void;
}
