<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

final readonly class VideoTaskView
{
    /**
     * @param list<PartialVideoView> $partialVideos
     */
    public function __construct(
        public VideoTaskSummaryView $summary,
        public array $partialVideos,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->summary->toArray() + ['partial_videos' => $this->partialVideosAsArray()];
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
