<?php

declare(strict_types=1);

namespace App\Animation\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'animation_jobs')]
class AnimationJobEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    public string $id;

    #[ORM\Column(type: 'string', length: 32)]
    public string $status; // PENDING|RUNNING|SUCCEEDED|FAILED

    #[ORM\Column(type: 'text')]
    public string $prompt;

    #[ORM\Column(type: 'string', length: 255)]
    public string $imageUrl; // URL pública (/storage/...)

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $operationId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $videoUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
}
