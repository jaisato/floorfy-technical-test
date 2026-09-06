<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Tests\Support\ApiTestCase;
use App\Ui\Http\Response\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELETE /api/tasks/{id} (cancel) and POST /api/tasks/{id}/retry through the
 * HTTP stack, including the 409s a wrong status gets.
 */
final class TaskLifecycleTest extends ApiTestCase
{
    private const string UNKNOWN = '0195c6a0-1c37-7000-8000-0000000000ff';

    public function testAPendingTaskIsCanceledAndAnsweredWithItsNewState(): void
    {
        $taskId = $this->createTask();

        $this->client->request('DELETE', '/api/tasks/'.$taskId);

        $this->assertStatus(Response::HTTP_OK);
        $body = $this->responseBody();
        self::assertSame($taskId, $body['task_id']);
        self::assertSame('canceled', $body['status']);
        self::assertArrayHasKey('partial_videos', $body);

        self::assertSame('canceled', $this->connection()->fetchOne('SELECT status FROM video_tasks WHERE id = ?', [$taskId]));
    }

    public function testAProcessingTaskCanBeCanceled(): void
    {
        $taskId = $this->createTask();
        $this->setStatus($taskId, 'processing');

        $this->client->request('DELETE', '/api/tasks/'.$taskId);

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame('canceled', $this->responseBody()['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function settledStatuses(): iterable
    {
        yield 'completed' => ['completed'];
        yield 'failed' => ['failed'];
        yield 'canceled' => ['canceled'];
    }

    #[DataProvider('settledStatuses')]
    public function testASettledTaskCannotBeCanceled(string $status): void
    {
        $taskId = $this->createTask();
        $this->setStatus($taskId, $status);

        $this->client->request('DELETE', '/api/tasks/'.$taskId);

        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertProblem(Response::HTTP_CONFLICT);
        $detail = $this->responseBody()['detail'];
        self::assertIsString($detail);
        self::assertStringContainsString('"'.$status.'" -> "canceled"', $detail);
        self::assertSame($status, $this->connection()->fetchOne('SELECT status FROM video_tasks WHERE id = ?', [$taskId]));
    }

    public function testCancelingAnUnknownTaskIsNotFound(): void
    {
        $this->client->request('DELETE', '/api/tasks/'.self::UNKNOWN);

        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertProblem(Response::HTTP_NOT_FOUND);
    }

    public function testACanceledTaskShowsUpUnderItsOwnStatusFilter(): void
    {
        $taskId = $this->createTask();
        $this->createTask();
        $this->client->request('DELETE', '/api/tasks/'.$taskId);

        $this->client->request('GET', '/api/tasks?status=canceled');

        self::assertSame([$taskId], array_column($this->responseBody()['items'], 'task_id'));
    }

    public function testAFailedTaskIsQueuedAgainWithItsCompletedPartsKept(): void
    {
        $taskId = $this->createTask(3);
        $this->setStatus($taskId, 'failed', 'No se pudieron generar 2 de 3 vídeos parciales.');
        $this->connection()->executeStatement(
            "UPDATE partial_videos SET status = 'completed', video_path = '/videos/partial_a.mp4' WHERE task_id = ? AND position = 0",
            [$taskId],
        );
        $this->connection()->executeStatement(
            "UPDATE partial_videos SET status = 'failed', error_message = 'la descarga falló' WHERE task_id = ? AND position = 1",
            [$taskId],
        );
        $this->transport('async')->reset();

        $this->client->request('POST', '/api/tasks/'.$taskId.'/retry');

        $this->assertStatus(Response::HTTP_ACCEPTED);
        $body = $this->responseBody();
        self::assertSame('pending', $body['status']);
        self::assertNull($body['error']);
        self::assertSame(
            ['completed', 'pending', 'pending'],
            array_column($body['partial_videos'], 'status'),
        );
        self::assertSame(['completed' => 1, 'failed' => 0, 'pending' => 2, 'total' => 3, 'percent' => 33], $body['progress']);

        $messages = $this->transport('async')->getSent();
        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessVideoTaskCommand::class, $messages[0]->getMessage());
        self::assertSame($taskId, $messages[0]->getMessage()->taskId);
    }

    public function testACanceledTaskCanBeRetried(): void
    {
        $taskId = $this->createTask();
        $this->client->request('DELETE', '/api/tasks/'.$taskId);

        $this->client->request('POST', '/api/tasks/'.$taskId.'/retry');

        $this->assertStatus(Response::HTTP_ACCEPTED);
        self::assertSame('pending', $this->responseBody()['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function statusesThatCannotBeRetried(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'processing' => ['processing'];
        yield 'completed' => ['completed'];
    }

    #[DataProvider('statusesThatCannotBeRetried')]
    public function testOnlyAFailedOrCanceledTaskCanBeRetried(string $status): void
    {
        $taskId = $this->createTask();
        $this->setStatus($taskId, $status);
        $this->transport('async')->reset();

        $this->client->request('POST', '/api/tasks/'.$taskId.'/retry');

        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertProblem(Response::HTTP_CONFLICT);
        self::assertSame([], $this->transport('async')->getSent(), 'a refused retry must not queue anything');
    }

    public function testRetryingAnUnknownTaskIsNotFound(): void
    {
        $this->client->request('POST', '/api/tasks/'.self::UNKNOWN.'/retry');

        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertProblem(Response::HTTP_NOT_FOUND);
    }

    public function testAnIdThatIsNotAUuidIsNotFoundOnBothOperations(): void
    {
        $this->client->request('DELETE', '/api/tasks/not-a-uuid');
        $this->assertStatus(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/api/tasks/not-a-uuid/retry');
        $this->assertStatus(Response::HTTP_NOT_FOUND);
    }

    private function assertProblem(int $status): void
    {
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client->getResponse()->headers->get('Content-Type'));

        $body = $this->responseBody();

        self::assertSame('about:blank', $body['type']);
        self::assertSame($status, $body['status']);
        self::assertIsString($body['detail']);
    }

    private function createTask(int $images = 1): string
    {
        $list = [];
        for ($i = 0; $i < $images; ++$i) {
            $list[] = ['url' => \sprintf('https://example.com/%d.png', $i), 'transition' => 'pan'];
        }

        $this->json('POST', '/api/tasks', ['images' => $list]);
        $this->assertStatus(Response::HTTP_CREATED);

        $taskId = $this->responseBody()['task_id'];
        self::assertIsString($taskId);

        return $taskId;
    }

    private function setStatus(string $taskId, string $status, ?string $error = null): void
    {
        $this->connection()->executeStatement(
            'UPDATE video_tasks SET status = ?, error_message = ? WHERE id = ?',
            [$status, $error, $taskId],
        );
    }
}
