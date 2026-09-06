<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_tasks')]
// The listing filters by status and by a created_at range and orders by
// (created_at DESC, id DESC): each composite carries the sort key, so a page is
// an index range read backwards rather than a sort, and the single-column
// lookups (the worker's status check) use the same indexes as a prefix.
#[ORM\Index(columns: ['status', 'created_at', 'id'], name: 'idx_video_tasks_status_created_at_id')]
#[ORM\Index(columns: ['created_at', 'id'], name: 'idx_video_tasks_created_at_id')]
// The retention job scans settled tasks last touched before a cutoff that have
// not been pruned yet.
#[ORM\Index(columns: ['pruned_at', 'updated_at'], name: 'idx_video_tasks_pruned_at_updated_at')]
// The recovery sweep looks for tasks left behind by a publish that never made
// it to the broker: still pending, or settled with an undelivered callback.
#[ORM\Index(columns: ['status', 'updated_at'], name: 'idx_video_tasks_status_updated_at')]
#[ORM\Index(columns: ['callback_notified_at', 'updated_at'], name: 'idx_video_tasks_callback_notified_at_updated_at')]
class VideoTaskEntity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON)]
    public array $payload = [];

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $status;

    #[ORM\Column(name: 'final_video_url', type: Types::STRING, length: 2048, nullable: true)]
    public ?string $finalVideoUrl = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    public ?string $errorMessage = null;

    /** As wide as the image URLs: the same validator bounds both. */
    #[ORM\Column(name: 'callback_url', type: Types::STRING, length: 2048, nullable: true)]
    public ?string $callbackUrl = null;

    /**
     * How this task is rendered: duration, fps, resolution and crossfade.
     * Null on rows written before the options existed.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'render_options', type: Types::JSON, nullable: true)]
    public ?array $renderOptions = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    /**
     * When the callback for this task was delivered, if it was. Null on a task
     * that asked for none, and on one whose notification is still owed - which
     * is what the recovery sweep looks for.
     */
    #[ORM\Column(name: 'callback_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $callbackNotifiedAt = null;

    /**
     * When the recovery sweep last published a notification for this task.
     *
     * The sweep republishes what it believes was lost, and "not delivered yet"
     * is not the same as "lost": a delivery the transport is still retrying
     * has neither reached the client nor stamped callbackNotifiedAt, so every
     * sweep in the meantime published another one and the client got the POST
     * again and again. Stamping the attempt makes the sweep's own republish
     * something it can see, so it waits out the same cutoff before trying that
     * task once more.
     */
    #[ORM\Column(name: 'callback_attempted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $callbackAttemptedAt = null;

    /** When the retention job deleted this task's videos, if it has. */
    #[ORM\Column(name: 'pruned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $prunedAt = null;
}
