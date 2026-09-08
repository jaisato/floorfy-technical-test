<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Port\PartialVideoRepository;

final class InMemoryPartialVideoRepository implements PartialVideoRepository
{
    /** @var array<string, PartialVideo> */
    private array $partials = [];

    public function save(PartialVideo $partial): void
    {
        $this->partials[$partial->id()->value] = $partial;
    }

    public function saveAll(array $partials): void
    {
        foreach ($partials as $partial) {
            $this->save($partial);
        }
    }

    public function listByTaskId(UuidValue $taskId): array
    {
        $found = array_values(array_filter(
            $this->partials,
            static fn (PartialVideo $partial): bool => $partial->taskId()->value === $taskId->value,
        ));

        usort($found, static fn (PartialVideo $a, PartialVideo $b): int => $a->position() <=> $b->position());

        return $found;
    }

    /** @return list<PartialVideo> */
    public function all(): array
    {
        return array_values($this->partials);
    }
}
