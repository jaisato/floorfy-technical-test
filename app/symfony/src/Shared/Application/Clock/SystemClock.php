<?php
declare(strict_types=1);

namespace App\Shared\Application\Clock;

use App\Shared\Domain\ValueObject\DateTimeValue;

final class SystemClock implements Clock
{
    public function now(): DateTimeValue
    {
        return DateTimeValue::now();
    }
}
