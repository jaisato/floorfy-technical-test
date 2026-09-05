<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for tests that talk to a real database.
 *
 * The schema is created once by tests/bootstrap.php - from the mapping, so the
 * suite runs on SQLite with no services at all. Tests that pin MySQL's own
 * behaviour carry the "mysql" group and run in CI.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // Children first: partial_videos has a foreign key onto video_tasks.
        $this->connection()->executeStatement('DELETE FROM partial_videos');
        $this->connection()->executeStatement('DELETE FROM video_tasks');
        $this->entityManager->clear();
    }

    protected function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    protected function isMySql(): bool
    {
        return str_contains($this->connection()->getDatabasePlatform()::class, 'MySQL');
    }
}
