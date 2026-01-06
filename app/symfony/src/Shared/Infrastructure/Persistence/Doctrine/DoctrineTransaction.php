<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTransaction implements Transaction
{
    public function __construct(private EntityManagerInterface $em) {}

    public function run(callable $fn): mixed
    {
        return $this->em->wrapInTransaction($fn);
    }
}
