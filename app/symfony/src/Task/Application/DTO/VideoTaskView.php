<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

final readonly class VideoTaskView
{
    /**
     * @param list<PartialVideoView> $partialVideos
     */
    public function __construct(
        public string $taskId,
        public string $status,
        public array $partialVideos,
        public ?string $finalVideoUrl,
        public ?string $error,
    ) {
    }

    /** @return list<array<string, string|null>> */
    public function partialVideosAsArray(): array
    {
        return array_map(
            static fn (PartialVideoView $partial): array => $partial->toArray(),
            $this->partialVideos,
        );
    }
}
