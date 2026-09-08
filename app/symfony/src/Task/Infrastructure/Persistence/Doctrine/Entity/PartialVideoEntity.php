<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'partial_videos')]
#[ORM\Index(columns: ['task_id', 'position'], name: 'idx_partial_videos_task_position')]
class PartialVideoEntity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: VideoTaskEntity::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public VideoTaskEntity $task;

    /**
     * Image URLs are user input. VARCHAR(255) was shorter than the longest URL
     * the API accepts, so a legal request could only fail at INSERT time, as a
     * 500 from the worker.
     */
    #[ORM\Column(name: 'image_url', type: Types::STRING, length: 2048)]
    public string $imageUrl;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $transition;

    /**
     * Playback order. Ordering by created_at is not deterministic: every part of
     * a task is created in the same request, and the column has one-second
     * resolution, so the concatenation order was whatever the database returned.
     */
    #[ORM\Column(type: Types::INTEGER)]
    public int $position = 0;

    /** Seconds this clip lasts; null means whatever the task says. */
    #[ORM\Column(name: 'duration_seconds', type: Types::FLOAT, nullable: true)]
    public ?float $durationSeconds = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $status;

    #[ORM\Column(name: 'video_path', type: Types::STRING, length: 2048, nullable: true)]
    public ?string $videoPath = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;
}
