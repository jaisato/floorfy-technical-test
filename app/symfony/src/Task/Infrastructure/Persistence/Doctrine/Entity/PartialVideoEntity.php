<?php
declare(strict_types=1);

namespace App\Task\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'partial_videos')]
#[ORM\Index(columns: ['task_id'], name: 'idx_partial_videos_task_id')]
class PartialVideoEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    public string $id;

    #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
    public string $taskId;

    #[ORM\Column(type: 'string', length: 255)]
    public string $imageUrl;

    #[ORM\Column(type: 'string', length: 32)]
    public string $transition;

    #[ORM\Column(type: 'string', length: 32)]
    public string $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $videoPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
}
