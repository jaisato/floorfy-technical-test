<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class TaskControllerTest extends ApiTestCase
{
    public function testCreatingATaskAnswers201WithAnIdToPoll(): void
    {
        $this->json('POST', '/api/tasks', ['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'zoom_in'],
        ]]);

        $this->assertStatus(Response::HTTP_CREATED);

        $body = $this->responseBody();

        self::assertSame('pending', $body['status']);
        self::assertIsString($body['task_id']);
        self::assertNotSame('', $body['task_id']);
    }

    public function testCreatingATaskQueuesExactlyOneProcessingMessage(): void
    {
        $this->json('POST', '/api/tasks', ['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'pan'],
        ]]);

        $messages = $this->transport('async')->getSent();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessVideoTaskCommand::class, $messages[0]->getMessage());
        self::assertSame($this->responseBody()['task_id'], $messages[0]->getMessage()->taskId);
    }

    public function testTheTaskAndItsPartsAreStored(): void
    {
        $this->json('POST', '/api/tasks', ['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'zoom_out'],
        ]]);

        $taskId = $this->responseBody()['task_id'];

        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM video_tasks WHERE id = ?',
            [$taskId],
        ));

        self::assertSame(
            ['https://example.com/a.png', 'https://example.com/b.png'],
            $this->connection()->fetchFirstColumn(
                'SELECT image_url FROM partial_videos WHERE task_id = ? ORDER BY position',
                [$taskId],
            ),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'no images key' => [['other' => []]];
        yield 'images is not a list' => [['images' => 'https://example.com/a.png']];
        yield 'empty list' => [['images' => []]];
        yield 'missing url' => [['images' => [['transition' => 'pan']]]];
        yield 'missing transition' => [['images' => [['url' => 'https://example.com/a.png']]]];
        yield 'unknown transition' => [['images' => [['url' => 'https://example.com/a.png', 'transition' => 'spin']]]];
        yield 'url is not a url' => [['images' => [['url' => 'not a url', 'transition' => 'pan']]]];
        yield 'url has no tld' => [['images' => [['url' => 'http://localhost/a.png', 'transition' => 'pan']]]];
        yield 'extra field' => [['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan', 'evil' => 1]]]];
        yield 'url is a list' => [['images' => [['url' => ['https://example.com/a.png'], 'transition' => 'pan']]]];
        yield 'element is not an object' => [['images' => ['https://example.com/a.png']]];
    }

    #[DataProvider('invalidPayloads')]
    public function testAnInvalidPayloadIsRefusedWithoutQueueingAnything(mixed $payload): void
    {
        $this->json('POST', '/api/tasks', $payload);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertSame('Validation failed', $this->responseBody()['error']);
        self::assertSame([], $this->transport('async')->getSent());
    }

    public function testMalformedJsonIsRefused(): void
    {
        $this->json('POST', '/api/tasks', '{"images": ');

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertSame('Invalid JSON payload', $this->responseBody()['error']);
    }

    /**
     * One task is one ffmpeg run per image on a single worker; without a
     * ceiling a single request decides how long every other task waits.
     */
    public function testMoreImagesThanTheLimitAreRefused(): void
    {
        $images = array_fill(0, 21, ['url' => 'https://example.com/a.png', 'transition' => 'pan']);

        $this->json('POST', '/api/tasks', ['images' => $images]);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    /**
     * image_url is 2048 characters wide, and so is the constraint: a URL the
     * validator accepts has to be one the INSERT accepts, or a legal-looking
     * request comes back as a 500 from the worker.
     */
    public function testAUrlLongerThanTheColumnIsRefusedByTheApi(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 2100).'.png';

        $this->json('POST', '/api/tasks', ['images' => [['url' => $url, 'transition' => 'pan']]]);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public function testAUrlThatJustFitsIsAccepted(): void
    {
        $prefix = 'https://example.com/';
        $url = $prefix.str_repeat('a', 2048 - \strlen($prefix));

        $this->json('POST', '/api/tasks', ['images' => [['url' => $url, 'transition' => 'pan']]]);

        $this->assertStatus(Response::HTTP_CREATED);
    }

    public function testReadingBackATaskShowsItsPartsAsPending(): void
    {
        $taskId = $this->createTask();

        $this->client->request('GET', '/api/tasks/'.$taskId);

        $this->assertStatus(Response::HTTP_OK);

        $body = $this->responseBody();

        self::assertSame($taskId, $body['task_id']);
        self::assertSame('pending', $body['status']);
        self::assertNull($body['error']);
        self::assertSame([
            [
                'id' => $body['partial_videos'][0]['id'],
                'image_url' => 'https://example.com/a.png',
                'transition' => 'zoom_in',
                'status' => 'pending',
                'video_url' => null,
                'error' => null,
            ],
        ], $body['partial_videos']);
    }

    public function testTheFinalEndpointReportsNoUrlUntilTheVideoExists(): void
    {
        $taskId = $this->createTask();

        $this->client->request('GET', '/api/tasks/'.$taskId.'/final');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame(
            ['task_id' => $taskId, 'status' => 'pending', 'final_video_url' => null],
            $this->responseBody(),
        );
    }

    public function testTheFinalEndpointReportsTheUrlOnceTheTaskIsDone(): void
    {
        $taskId = $this->createTask();

        $this->connection()->executeStatement(
            "UPDATE video_tasks SET status = 'completed', final_video_url = ? WHERE id = ?",
            ['http://localhost/videos/final_'.$taskId.'.mp4', $taskId],
        );

        $this->client->request('GET', '/api/tasks/'.$taskId.'/final');

        self::assertSame(
            'http://localhost/videos/final_'.$taskId.'.mp4',
            $this->responseBody()['final_video_url'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function readEndpoints(): iterable
    {
        yield 'task' => ['/api/tasks/%s'];
        yield 'final video' => ['/api/tasks/%s/final'];
    }

    #[DataProvider('readEndpoints')]
    public function testAnUnknownTaskIsANotFound(string $template): void
    {
        $this->client->request('GET', \sprintf($template, '0195c6a0-1c37-7000-8000-0000000000ff'));

        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertSame('Task not found', $this->responseBody()['error']);
    }

    /** A typo in the path is a task that does not exist, not a 500. */
    #[DataProvider('readEndpoints')]
    public function testAnIdThatIsNotAUuidIsANotFound(string $template): void
    {
        $this->client->request('GET', \sprintf($template, 'not-a-uuid'));

        $this->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /** @return iterable<string, array{string, string}> */
    public static function wrongMethods(): iterable
    {
        yield 'GET on the collection' => ['GET', '/api/tasks'];
        yield 'DELETE on the collection' => ['DELETE', '/api/tasks'];
        yield 'POST on one task' => ['POST', '/api/tasks/0195c6a0-1c37-7000-8000-0000000000ff'];
        yield 'PUT on the final video' => ['PUT', '/api/tasks/0195c6a0-1c37-7000-8000-0000000000ff/final'];
    }

    #[DataProvider('wrongMethods')]
    public function testAnUnsupportedMethodIsRejected(string $method, string $uri): void
    {
        $this->client->request($method, $uri);

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function createTask(): string
    {
        $this->json('POST', '/api/tasks', ['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'zoom_in'],
        ]]);

        $taskId = $this->responseBody()['task_id'];

        self::assertIsString($taskId);

        return $taskId;
    }
}
