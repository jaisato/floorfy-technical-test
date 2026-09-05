<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Base for tests that go through the HTTP stack.
 *
 * The schema is created once by tests/bootstrap.php; each test starts from
 * empty tables so nothing depends on what ran before it.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        // Exceptions are caught, as they are in production: the status a client
        // is actually served is part of what these tests check.
        $this->client = self::createClient();

        $this->clearTables();
    }

    /**
     * Asserts the status, quoting the body so a surprise 500 says why.
     */
    protected function assertStatus(int $expected): void
    {
        $response = $this->client->getResponse();

        self::assertSame(
            $expected,
            $response->getStatusCode(),
            substr((string) $response->getContent(), 0, 2000),
        );
    }

    /**
     * @param array<string, mixed>|string $body
     */
    protected function json(string $method, string $uri, array|string $body = []): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<mixed> */
    protected function responseBody(): array
    {
        $content = $this->client->getResponse()->getContent();

        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    protected function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    private function clearTables(): void
    {
        $connection = $this->connection();

        // Children first: partial_videos has a foreign key onto video_tasks.
        $connection->executeStatement('DELETE FROM partial_videos');
        $connection->executeStatement('DELETE FROM video_tasks');
    }
}
