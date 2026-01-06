<?php
declare(strict_types=1);

namespace App\Animation\Domain\Port;

use App\Animation\Domain\Entity\AnimationJob;
use App\Shared\Domain\ValueObject\UuidValue;

interface AnimationJobRepository
{
    public function save(AnimationJob $job): void;
    public function get(UuidValue $id): ?AnimationJob;
    public function getByOperationId(string $operationId): ?AnimationJob;
}
