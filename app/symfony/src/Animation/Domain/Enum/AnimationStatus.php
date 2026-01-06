<?php
declare(strict_types=1);

namespace App\Animation\Domain\Enum;

enum AnimationStatus: string
{
    case REQUESTED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
