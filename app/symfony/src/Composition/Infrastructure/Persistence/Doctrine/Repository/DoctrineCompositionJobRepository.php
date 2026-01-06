<?php

declare(strict_types=1);

namespace App\Composition\Infrastructure\Persistence\Doctrine\Repository;

use App\Composition\Domain\Entity\CompositionJob;
use App\Composition\Domain\Enum\CompositionStatus;
use App\Composition\Domain\Port\CompositionJobRepository;
use App\Composition\Infrastructure\Persistence\Doctrine\Entity\CompositionJobEntity;
use App\Shared\Domain\ValueObject\UuidValue;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineCompositionJobRepository implements CompositionJobRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(CompositionJob $job): void
    {
        $id = $job->id()->value;

        /** @var CompositionJobEntity|null $entity */
        $entity = $this->em->find(CompositionJobEntity::class, $id);

        if (!$entity) {
            $entity = new CompositionJobEntity();
            $entity->id = $id;
            $entity->createdAt = $job->createdAt();
        }

        $entity->status = $job->status()->value;
        $entity->animationVideoUrls = $job->animationVideoUrls(); // JSON
        $entity->outputUrl = $job->outputUrl();
        $entity->errorMessage = $job->errorMessage();
        $entity->updatedAt = $job->updatedAt();

        $this->em->persist($entity);
        $this->em->flush();
    }

    public function get(UuidValue $id): ?CompositionJob
    {
        /** @var CompositionJobEntity|null $entity */
        $entity = $this->em->find(CompositionJobEntity::class, $id->value);

        if (!$entity) {
            return null;
        }

        $status = $this->mapStatus($entity->status);

        return CompositionJob::rehydrate(
            id: $id,
            animationVideoUrls: $this->normalizeStringList($entity->animationVideoUrls),
            status: $status,
            outputUrl: $entity->outputUrl,
            errorMessage: $entity->errorMessage,
            createdAt: $entity->createdAt,
            updatedAt: $entity->updatedAt,
        );
    }

    private function mapStatus(string $raw): CompositionStatus
    {
        if (method_exists(CompositionStatus::class, 'tryFrom')) {
            /** @var CompositionStatus|null $s */
            $s = CompositionStatus::tryFrom($raw);
            return $s ?? CompositionStatus::FAILED;
        }

        return match ($raw) {
            'QUEUED' => CompositionStatus::QUEUED,
            'RUNNING' => CompositionStatus::RUNNING,
            'SUCCEEDED' => CompositionStatus::SUCCEEDED,
            default => CompositionStatus::FAILED,
        };
    }

    /** @return list<string> */
    private function normalizeStringList(array $value): array
    {
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }
}
