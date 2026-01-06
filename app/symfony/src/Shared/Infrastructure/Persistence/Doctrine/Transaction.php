<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine;

interface Transaction
{
    public function run(callable $fn): mixed;
}
