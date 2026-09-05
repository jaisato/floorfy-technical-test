<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

final readonly class PartialVideoView
{
    public function __construct(
        public string $id,
        public string $imageUrl,
        public string $transition,
        public string $status,
        public ?string $videoUrl,
        public ?string $error,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'image_url' => $this->imageUrl,
            'transition' => $this->transition,
            'status' => $this->status,
            'video_url' => $this->videoUrl,
            'error' => $this->error,
        ];
    }
}
