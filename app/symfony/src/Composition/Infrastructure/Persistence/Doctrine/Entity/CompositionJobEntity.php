<?php

declare(strict_types=1);

namespace App\Composition\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'composition_jobs')]
class CompositionJobEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    public string $id;

    #[ORM\Column(type: 'string', length: 32)]
    public string $status; // QUEUED|RUNNING|SUCCEEDED|FAILED

    #[ORM\Column(type: 'json')]
    public array $animationVideoUrls = []; // list<string>

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $outputUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
}
