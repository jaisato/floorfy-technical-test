<?php
declare(strict_types=1);

namespace App\Composition\Domain\Enum;

enum CompositionStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
