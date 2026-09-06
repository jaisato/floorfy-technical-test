<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
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
        public ?string $callbackUrl,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * The summary of a task as the write side holds it. The listing builds the
     * same shape from SQL; a test pins the two against each other.
     *
     * @param list<PartialVideo> $partials
     */
    public static function fromTask(VideoTask $task, array $partials): self
    {
        return new self(
            $task->id()->value,
            $task->status()->value,
            TaskProgress::ofParts($partials),
            $task->finalVideoUrl(),
            $task->errorMessage(),
            $task->callbackUrl(),
            $task->createdAt()->toIso8601(),
            $task->updatedAt()->toIso8601(),
        );
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
            'callback_url' => $this->callbackUrl,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
