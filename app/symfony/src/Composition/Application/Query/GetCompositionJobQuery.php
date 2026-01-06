<?php

declare(strict_types=1);

namespace App\Composition\Application\Query;

use App\Shared\Domain\ValueObject\UuidValue;

final readonly class GetCompositionJobQuery
{
    public UuidValue $jobId;

    public function __construct(string $jobId)
    {
        $this->jobId = UuidValue::fromString($jobId);
    }
}
