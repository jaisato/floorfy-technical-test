<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Tests\Support\ApiTestCase;
use App\Ui\Http\Response\ApiProblem;
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

    public function testACallbackUrlIsStoredWithTheTask(): void
    {
        $this->json('POST', '/api/tasks', [
            'images' => [['url' => 'https://example.com/a.png', 'transition' => 'zoom_in']],
            // An address literal: the guard resolves names, and the suite never
            // touches the network.
            'callback_url' => 'https://8.8.8.8/hooks/video-tasks',
        ]);

        $this->assertStatus(Response::HTTP_CREATED);
        $taskId = $this->responseBody()['task_id'];

        $this->client->request('GET', '/api/tasks/'.$taskId);

        self::assertSame('https://8.8.8.8/hooks/video-tasks', $this->responseBody()['callback_url']);
    }

    /**
     * A callback URL is the client's own, and a token in its userinfo or its
     * query is the ordinary way to write one. Served whole, GET /api/tasks/{id}
     * and every item of GET /api/tasks handed that credential to whoever
     * asked - and the listing needs no id, on an API that is open unless a key
     * is configured and shared between clients when it is. The endpoint is
     * still named, which is what the field is for.
     */
    public function testACallbackUrlIsServedWithoutItsCredentials(): void
    {
        $this->json('POST', '/api/tasks', [
            'images' => [['url' => 'https://example.com/a.png', 'transition' => 'zoom_in']],
            'callback_url' => 'https://bot:s3cr3t@8.8.8.8/hooks/video-tasks?token=deadbeef',
        ]);
        $this->assertStatus(Response::HTTP_CREATED);
        $taskId = $this->responseBody()['task_id'];

        $this->client->request('GET', '/api/tasks/'.$taskId);
        self::assertSame('https://8.8.8.8/hooks/video-tasks?…', $this->responseBody()['callback_url']);
        $this->assertNothingSecretInTheResponse();

        // The listing is the one an unrelated caller reaches without knowing an id.
        $this->client->request('GET', '/api/tasks');
        $this->assertNothingSecretInTheResponse();
        self::assertSame('https://8.8.8.8/hooks/video-tasks?…', $this->responseBody()['items'][0]['callback_url']);
    }

    private function assertNothingSecretInTheResponse(): void
    {
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('s3cr3t', $content);
        self::assertStringNotContainsString('deadbeef', $content);
    }

    public function testATaskWithoutACallbackUrlReportsNone(): void
    {
        $taskId = $this->createTask();

        $this->client->request('GET', '/api/tasks/'.$taskId);

        self::assertNull($this->responseBody()['callback_url']);
    }

    /**
     * A callback URL is an outbound request to an address the caller chose -
     * the same thing an image URL is - and faces the same guard, at request
     * time: a URL pointing inside the network is a 400 now, not a worker
     * discovering it later with nobody left to tell.
     */
    public function testACallbackUrlInsideTheNetworkIsRefused(): void
    {
        $this->json('POST', '/api/tasks', [
            'images' => [['url' => 'https://example.com/a.png', 'transition' => 'zoom_in']],
            'callback_url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertArrayHasKey('callbackUrl', $this->responseBody()['violations']);
        self::assertSame([], $this->transport('async')->getSent());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCallbackUrls(): iterable
    {
        yield 'not a url' => ['not a url'];
        yield 'ftp' => ['ftp://8.8.8.8/hook'];
        yield 'odd port' => ['https://8.8.8.8:8443/hook'];
        yield 'loopback' => ['http://127.0.0.1/hook'];
        yield 'not text' => [['https://8.8.8.8/hook']];
        yield 'empty' => [''];
        yield 'too long' => ['https://8.8.8.8/'.str_repeat('a', 2100)];
    }

    #[DataProvider('invalidCallbackUrls')]
    public function testAnInvalidCallbackUrlIsAValidationProblem(mixed $callbackUrl): void
    {
        $this->json('POST', '/api/tasks', [
            'images' => [['url' => 'https://example.com/a.png', 'transition' => 'zoom_in']],
            'callback_url' => $callbackUrl,
        ]);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertArrayHasKey('callbackUrl', $this->responseBody()['violations']);
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
        // A JSON object passes is_array() and every constraint under it, and
        // the parts are then assembled in the order its members happen to sit
        // in the document - so a client that numbered them gets its scenes in
        // whatever order it typed them, with nothing said.
        yield 'images is an object, not a list' => [['images' => ['2' => ['url' => 'https://example.com/b.png', 'transition' => 'pan'], '1' => ['url' => 'https://example.com/a.png', 'transition' => 'pan']]]];
        yield 'images is an object with named keys' => [['images' => ['first' => ['url' => 'https://example.com/a.png', 'transition' => 'pan']]]];
    }

    #[DataProvider('invalidPayloads')]
    public function testAnInvalidPayloadIsRefusedWithoutQueueingAnything(mixed $payload): void
    {
        $this->json('POST', '/api/tasks', $payload);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertProblem(Response::HTTP_BAD_REQUEST);
        self::assertNotEmpty($this->responseBody()['violations']);
        self::assertSame([], $this->transport('async')->getSent());
    }

    public function testMalformedJsonIsRefused(): void
    {
        $this->json('POST', '/api/tasks', '{"images": ');

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertProblem(Response::HTTP_BAD_REQUEST);
        self::assertArrayNotHasKey('violations', $this->responseBody());
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
        self::assertNull($body['final_video_url']);
        self::assertSame(['completed' => 0, 'failed' => 0, 'pending' => 1, 'total' => 1, 'percent' => 0], $body['progress']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/', $body['created_at']);
        self::assertSame($body['created_at'], $body['updated_at']);
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

    public function testTheProgressFollowsTheParts(): void
    {
        $this->json('POST', '/api/tasks', ['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/b.png', 'transition' => 'pan'],
            ['url' => 'https://example.com/c.png', 'transition' => 'pan'],
        ]]);
        $taskId = $this->responseBody()['task_id'];

        $this->connection()->executeStatement(
            "UPDATE partial_videos SET status = 'completed', video_path = '/videos/partial_a.mp4' WHERE task_id = ? AND position = 0",
            [$taskId],
        );
        $this->connection()->executeStatement(
            "UPDATE partial_videos SET status = 'failed', error_message = 'la descarga falló' WHERE task_id = ? AND position = 1",
            [$taskId],
        );

        $this->client->request('GET', '/api/tasks/'.$taskId);

        self::assertSame(
            ['completed' => 1, 'failed' => 1, 'pending' => 1, 'total' => 3, 'percent' => 33],
            $this->responseBody()['progress'],
        );
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
            ['/videos/final_'.$taskId.'.mp4', $taskId],
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
        self::assertProblem(Response::HTTP_NOT_FOUND);
    }

    /**
     * A typo in the path is a task that does not exist, not a 500 - and it is
     * answered by the router rather than by a controller, so it is also where
     * the single error contract earns its keep.
     */
    #[DataProvider('readEndpoints')]
    public function testAnIdThatIsNotAUuidIsANotFound(string $template): void
    {
        $this->client->request('GET', \sprintf($template, 'not-a-uuid'));

        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertProblem(Response::HTTP_NOT_FOUND);
    }

    /** @return iterable<string, array{string, string}> */
    public static function wrongMethods(): iterable
    {
        yield 'PATCH on the collection' => ['PATCH', '/api/tasks'];
        yield 'DELETE on the collection' => ['DELETE', '/api/tasks'];
        yield 'POST on one task' => ['POST', '/api/tasks/0195c6a0-1c37-7000-8000-0000000000ff'];
        yield 'PUT on the final video' => ['PUT', '/api/tasks/0195c6a0-1c37-7000-8000-0000000000ff/final'];
    }

    #[DataProvider('wrongMethods')]
    public function testAnUnsupportedMethodIsRejected(string $method, string $uri): void
    {
        $this->client->request($method, $uri);

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertProblem(Response::HTTP_METHOD_NOT_ALLOWED);

        // The Allow header the router produced survives the conversion.
        self::assertNotSame('', (string) $this->client->getResponse()->headers->get('Allow'));
    }

    /**
     * Every error, wherever it came from, is the same document: RFC 9457
     * problem+json, with no exception message and no echo of the request.
     */
    private function assertProblem(int $status): void
    {
        self::assertSame(
            ApiProblem::CONTENT_TYPE,
            $this->client->getResponse()->headers->get('Content-Type'),
        );

        $body = $this->responseBody();

        self::assertSame('about:blank', $body['type']);
        self::assertSame($status, $body['status']);
        self::assertIsString($body['title']);
        self::assertIsString($body['detail']);
        self::assertNotSame('', $body['detail']);
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
