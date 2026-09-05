<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

interface ImageFetcher
{
    /**
     * Downloads an image the API was given and returns the local path it landed on.
     *
     * @param string $relativeName path, relative to the fetcher's work directory,
     *                             to write to; it is sanitised by the adapter
     *
     * @throws \RuntimeException when the image cannot be fetched or is refused
     */
    public function fetch(string $imageUrl, string $relativeName): string;
}
