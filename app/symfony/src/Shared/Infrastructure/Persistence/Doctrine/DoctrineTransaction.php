<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine;

use App\Shared\Application\Transaction\Transaction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransaction implements Transaction
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function run(callable $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }
}
