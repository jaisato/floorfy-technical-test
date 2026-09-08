<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\RateLimit;

use App\Tests\Support\OverridesEnvironment;
use App\Ui\Http\Response\ApiProblem;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The optional cap on how often one caller may create tasks.
 *
 * The counters live in memory in the test environment, so the kernel has to
 * survive between requests: without disableReboot() every request would start
 * from a fresh count and the limit could never be reached.
 */
final class CreateTaskRateLimitTest extends WebTestCase
{
    use OverridesEnvironment;

    private ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironment();
    }

    /** The default: nothing is counted and nothing is refused. */
    public function testWithoutALimitTasksCanBeCreatedFreely(): void
    {
        $this->clientWithLimit(0);

        for ($i = 0; $i < 5; ++$i) {
            $this->createTask();
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        self::assertNull($this->client()->getResponse()->headers->get('X-RateLimit-Limit'));
    }

    public function testRequestsWithinTheLimitAreServed(): void
    {
        $this->clientWithLimit(3);

        $this->createTask();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('3', $this->client()->getResponse()->headers->get('X-RateLimit-Limit'));
        self::assertSame('2', $this->client()->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testTheRequestAfterTheLimitIsRefused(): void
    {
        $this->clientWithLimit(2);

        $this->createTask();
        $this->createTask();
        $this->createTask();

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client()->getResponse()->headers->get('Content-Type'));
    }

    /** A client that is told to wait needs to be told how long. */
    public function testARefusalSaysWhenToComeBack(): void
    {
        $this->clientWithLimit(1);

        $this->createTask();
        $this->createTask();

        $retryAfter = $this->client()->getResponse()->headers->get('Retry-After');

        self::assertIsString($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertSame('0', $this->client()->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    /** A refusal must happen before the task is written. */
    public function testARefusedRequestCreatesNothing(): void
    {
        $this->clientWithLimit(1);

        $this->createTask();
        $this->createTask();

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(1, $this->taskCount());
    }

    /**
     * A client repeats a request because it never saw the answer, and the
     * answer it gets back creates nothing. Charged to the quota, the very
     * timeout Idempotency-Key exists to paper over could push a well-behaved
     * client over its limit and turn the replay into a 429 - a task created,
     * an answer stored, and a client refused the only copy of it.
     */
    public function testAReplayDoesNotSpendTheCallersQuota(): void
    {
        $this->clientWithLimit(1);

        $this->createTask('the-lost-answer');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->createTask('the-lost-answer');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('true', $this->client()->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame(1, $this->taskCount());
    }

    /**
     * The other half of that order: the claim taken for a request the limiter
     * then refuses is given back. Stored, the 429 would be the answer replayed
     * for the whole TTL and the task would never be created, however long the
     * client waited out the window it was told to wait out.
     */
    public function testARefusedRequestDoesNotPinItsKeyToThe429(): void
    {
        $this->clientWithLimit(1);

        $this->createTask('first');
        $this->createTask('refused');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        // The window is a minute, so nothing here can wait it out; what this
        // asks is only that the key is free again, not that it is replayed.
        $this->createTask('refused');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNull($this->client()->getResponse()->headers->get('Idempotency-Replayed'));
    }

    /** Reads are cheap; only the request that starts ffmpeg is counted. */
    public function testReadsAreNotLimited(): void
    {
        $this->clientWithLimit(1);

        $this->createTask();

        for ($i = 0; $i < 5; ++$i) {
            $this->client()->request('GET', '/api/tasks');
            self::assertResponseIsSuccessful();
        }
    }

    private function clientWithLimit(int $limit): void
    {
        $this->overrideEnv('RATE_LIMIT_TASK_CREATION', (string) $limit);

        $this->client = self::createClient();
        // One kernel for the whole test: the in-memory counters live in it.
        $this->client->disableReboot();

        $connection = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DELETE FROM partial_videos');
        $connection->executeStatement('DELETE FROM video_tasks');
    }

    private function createTask(?string $idempotencyKey = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $idempotencyKey) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $this->client()->request(
            'POST',
            '/api/tasks',
            server: $server,
            content: '{"images":[{"url":"https://example.com/a.png","transition":"pan"}]}',
        );
    }

    private function taskCount(): int
    {
        $connection = $this->client()->getContainer()->get('doctrine.dbal.default_connection');

        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM video_tasks');
    }

    private function client(): KernelBrowser
    {
        self::assertNotNull($this->client);

        return $this->client;
    }
}
