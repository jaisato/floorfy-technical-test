<?php
declare(strict_types=1);

namespace App\Animation\Application\DTO;

final readonly class AnimationJobView
{
    public function __construct(
        public string $id,
        public string $imageUrl,
        public string $status,
        public ?string $videoUrl,
        public ?string $errorMessage,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
