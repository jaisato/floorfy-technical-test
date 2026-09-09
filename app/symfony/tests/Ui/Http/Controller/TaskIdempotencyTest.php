<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Shared\Infrastructure\Persistence\Doctrine\Idempotency\DbalIdempotencyStore;
use App\Tests\Support\ApiTestCase;
use App\Ui\Http\Idempotency\IdempotencyKeyListener;
use App\Ui\Http\Response\ApiProblem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key end to end: a client that lost the answer to POST /api/tasks
 * repeats the request and gets the first answer back, not a second video.
 */
final class TaskIdempotencyTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private const array PAYLOAD = ['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]];

    public function testARequestWithoutAKeyIsNotRecorded(): void
    {
        $this->json('POST', '/api/tasks', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CREATED);
        self::assertSame(0, $this->keyCount());
    }

    public function testTheFirstRequestWithAKeyIsAnsweredNormally(): void
    {
        $this->create('k-1', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CREATED);
        self::assertNull($this->client->getResponse()->headers->get(IdempotencyKeyListener::REPLAYED_HEADER));
        self::assertSame(1, $this->keyCount());
    }

    public function testRepeatingTheRequestReplaysTheFirstAnswerAndCreatesNothing(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $first = $this->responseBody();

        $this->create('k-1', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CREATED);
        self::assertSame($first, $this->responseBody());
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyKeyListener::REPLAYED_HEADER));
        self::assertSame(1, $this->taskCount());
    }

    /** The replayed answer is the whole answer, content type included. */
    public function testTheReplayIsStillJson(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $this->create('k-1', self::PAYLOAD);

        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /** A replay must not queue the video a second time. */
    public function testAReplayQueuesNoFurtherWork(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $this->transport('async')->reset();

        $this->create('k-1', self::PAYLOAD);

        self::assertSame([], $this->transport('async')->getSent());
    }

    public function testTheSameKeyWithADifferentBodyIsRefused(): void
    {
        $this->create('k-1', self::PAYLOAD);

        $this->create('k-1', ['images' => [['url' => 'https://example.com/b.png', 'transition' => 'pan']]]);

        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(1, $this->taskCount());
    }

    /**
     * A key whose row carries no response is one whose original request is
     * still in flight - the state a second, concurrent request finds.
     */
    public function testTheSameKeyWhileTheFirstRequestIsRunningIsRefused(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $this->connection()->executeStatement(
            'UPDATE '.DbalIdempotencyStore::TABLE.' SET response_status = NULL, response_body = NULL, response_content_type = NULL',
        );

        $this->create('k-1', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CONFLICT);
        self::assertSame(1, $this->taskCount());
    }

    /**
     * A key whose request died without answering - php-fpm stopped it at
     * max_execution_time, the container was replaced under it - has a row and
     * no response, exactly like one still running, and is told apart by its
     * age. Once the grace has passed the retry runs for real, instead of being
     * told "still running" until the key's TTL a day later.
     */
    public function testTheSameKeyAfterTheFirstRequestDiedWithoutAnsweringRunsForReal(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $first = $this->responseBody();
        $this->connection()->executeStatement(
            'UPDATE '.DbalIdempotencyStore::TABLE." SET response_status = NULL, response_body = NULL, response_content_type = NULL, created_at = '2000-01-01 00:00:00'",
        );

        $this->create('k-1', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CREATED);
        self::assertNull($this->client->getResponse()->headers->get(IdempotencyKeyListener::REPLAYED_HEADER), 'a fresh answer, not a replay');
        self::assertNotSame($first['task_id'], $this->responseBody()['task_id']);
        self::assertSame(2, $this->taskCount());
        self::assertSame(1, $this->keyCount());
    }

    /** Once the record is past its TTL the key is free and the request runs. */
    public function testAKeyIsUsableAgainOnceItsRecordHasExpired(): void
    {
        $this->create('k-1', self::PAYLOAD);
        $first = $this->responseBody();

        $this->connection()->executeStatement(
            'UPDATE '.DbalIdempotencyStore::TABLE." SET expires_at = '2000-01-01 00:00:00'",
        );

        $this->create('k-1', self::PAYLOAD);

        $this->assertStatus(Response::HTTP_CREATED);
        self::assertNotSame($first['task_id'], $this->responseBody()['task_id']);
        self::assertSame(2, $this->taskCount());
    }

    /** A refused body is an answer too; repeating it must not create a task. */
    public function testARejectedRequestIsReplayedAsTheSameRejection(): void
    {
        $this->create('k-1', ['images' => []]);
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $first = $this->responseBody();

        $this->create('k-1', ['images' => []]);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertSame($first, $this->responseBody());
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyKeyListener::REPLAYED_HEADER));
        self::assertSame(0, $this->taskCount());
    }

    public function testAKeyThatIsNotUsableIsRefusedBeforeAnythingHappens(): void
    {
        $this->create(str_repeat('k', IdempotencyKeyListener::MAX_KEY_LENGTH + 1), self::PAYLOAD);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertSame(0, $this->taskCount());
        self::assertSame(0, $this->keyCount());
    }

    /** The header only means something where the API says it does. */
    public function testTheHeaderIsIgnoredOnALookup(): void
    {
        $this->client->request('GET', '/api/tasks', server: ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame(0, $this->keyCount());
    }

    /** @param array<string, mixed> $payload */
    private function create(string $key, array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/tasks',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function taskCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM video_tasks');
    }

    private function keyCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM '.DbalIdempotencyStore::TABLE);
    }
}
