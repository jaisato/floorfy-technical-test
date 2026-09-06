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

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;
}
