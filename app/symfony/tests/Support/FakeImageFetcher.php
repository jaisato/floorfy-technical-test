<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Domain\Port\ImageFetcher;

final class FakeImageFetcher implements ImageFetcher
{
    /** @var list<string> */
    public array $fetched = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    public function __construct(private readonly string $workDir)
    {
    }

    public function failFor(string $imageUrl, \Throwable $error): void
    {
        $this->failures[$imageUrl] = $error;
    }

    public function fetch(string $imageUrl, string $relativeName): string
    {
        $this->fetched[] = $imageUrl;

        if (isset($this->failures[$imageUrl])) {
            throw $this->failures[$imageUrl];
        }

        $destination = $this->workDir.'/'.str_replace('/', '_', $relativeName).'.img';
        file_put_contents($destination, 'image bytes');

        return $destination;
    }
}
