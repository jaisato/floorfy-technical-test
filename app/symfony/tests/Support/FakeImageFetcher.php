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

    /** @var callable(string): void|null */
    private $onFetch;

    public function __construct(private readonly string $workDir)
    {
    }

    /**
     * Runs while a download is in progress - the moment a cancellation from
     * outside would land on a real worker.
     *
     * @param callable(string): void $hook receives the image URL
     */
    public function onFetch(callable $hook): void
    {
        $this->onFetch = $hook;
    }

    public function failFor(string $imageUrl, \Throwable $error): void
    {
        $this->failures[$imageUrl] = $error;
    }

    public function fetch(string $imageUrl, string $relativeName): string
    {
        $this->fetched[] = $imageUrl;

        if (null !== $this->onFetch) {
            ($this->onFetch)($imageUrl);
        }

        if (isset($this->failures[$imageUrl])) {
            throw $this->failures[$imageUrl];
        }

        $destination = $this->workDir.'/'.str_replace('/', '_', $relativeName).'.img';
        file_put_contents($destination, 'image bytes');

        return $destination;
    }
}
