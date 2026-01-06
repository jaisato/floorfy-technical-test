<?php

declare(strict_types=1);

namespace App\Composition\Application\DTO;

final readonly class CompositionJobView
{
    /**
     * @param list<string> $animationJobIds
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $animationJobIds,
        public ?string $outputUrl,
        public ?string $error,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
