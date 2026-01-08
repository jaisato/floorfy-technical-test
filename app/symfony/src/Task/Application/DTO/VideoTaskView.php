<?php
declare(strict_types=1);

namespace App\Task\Application\DTO;

final readonly class VideoTaskView
{
    /**
     * @param list<array{image_url:string, status:string}> $partialVideos
     */
    public function __construct(
        public string $taskId,
        public string $status,
        public array $partialVideos,
        public ?string $finalVideoUrl,
    ) {}
}
