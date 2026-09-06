<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

use App\Task\Domain\ValueObject\TaskProgress;

/**
 * A task without its parts: what a listing shows per item, and what the
 * single-task view is built on, so the two never drift apart.
 */
final readonly class VideoTaskSummaryView
{
    public function __construct(
        public string $taskId,
        public string $status,
        public TaskProgress $progress,
        public ?string $finalVideoUrl,
        public ?string $error,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'status' => $this->status,
            'progress' => $this->progress->toArray(),
            'final_video_url' => $this->finalVideoUrl,
            'error' => $this->error,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
