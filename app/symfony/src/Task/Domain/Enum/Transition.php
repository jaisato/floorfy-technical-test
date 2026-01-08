<?php
declare(strict_types=1);

namespace App\Task\Domain\Enum;

enum Transition: string
{
    case PAN = 'pan';
    case ZOOM_IN = 'zoom_in';
    case ZOOM_OUT = 'zoom_out';
}
