<?php
declare(strict_types=1);

namespace App\Composition\Domain\Port;

use App\Composition\Domain\Entity\CompositionJob;
use App\Shared\Domain\ValueObject\UuidValue;

interface CompositionJobRepository
{
    public function save(CompositionJob $job): void;
    public function get(UuidValue $id): ?CompositionJob;
}

