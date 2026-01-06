<?php
declare(strict_types=1);

namespace App\Shared\Application\Clock;

use App\Shared\Domain\ValueObject\DateTimeValue;

interface Clock
{
    public function now(): DateTimeValue;
}
