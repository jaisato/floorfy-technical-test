<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Tests\Support\ApiTestCase;
use App\Ui\Http\Response\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/tasks through the HTTP stack: the page shape, the filters, the
 * paging headers and what a bad query string gets.
 */
final class TaskListingTest extends ApiTestCase
{
    public function testAnEmptyListingIsAnEmptyPage(): void
    {
        $this->client->request('GET', '/api/tasks');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame(
            ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 20, 'pages' => 0, 'hasNext' => false],
            $this->responseBody(),
        );
        self::assertFalse($this->client->getResponse()->headers->has('Link'));
    }

    public function testEachItemIsTheSummaryOfTheTaskWithoutItsParts(): void
    {
        $taskId = $this->createTask(2);

        $this->client->request('GET', '/api/tasks');

        $item = $this->responseBody()['items'][0];

        self::assertSame($taskId, $item['task_id']);
        self::assertSame('pending', $item['status']);
        self::assertSame(['completed' => 0, 'failed' => 0, 'pending' => 2, 'total' => 2, 'percent' => 0], $item['progress']);
        self::assertNull($item['final_video_url']);
        self::assertNull($item['error']);
        self::assertArrayHasKey('created_at', $item);
        self::assertArrayHasKey('updated_at', $item);
        self::assertArrayNotHasKey('partial_videos', $item);

        // Minus the parts, the item is exactly the single-task view.
        $this->client->request('GET', '/api/tasks/'.$taskId);
        $single = $this->responseBody();
        unset($single['partial_videos']);
        self::assertSame($single, $item);
    }

    public function testTasksAreListedNewestFirst(): void
    {
        $oldest = $this->createTask();
        $this->setCreatedAt($oldest, '2026-01-01 10:00:00');
        $newest = $this->createTask();
        $this->setCreatedAt($newest, '2026-01-03 10:00:00');
        $middle = $this->createTask();
        $this->setCreatedAt($middle, '2026-01-02 10:00:00');

        $this->client->request('GET', '/api/tasks');

        self::assertSame([$newest, $middle, $oldest], array_column($this->responseBody()['items'], 'task_id'));
    }

    public function testTheStatusFilterAppliesAndUnknownStatusesAreRefused(): void
    {
        $this->createTask();
        $failed = $this->createTask();
        $this->connection()->executeStatement("UPDATE video_tasks SET status = 'failed', error_message = 'boom' WHERE id = ?", [$failed]);

        $this->client->request('GET', '/api/tasks?status=failed');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame([$failed], array_column($this->responseBody()['items'], 'task_id'));
        self::assertSame(1, $this->responseBody()['total']);

        $this->client->request('GET', '/api/tasks?status=exploded');

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertProblemWithViolationOn('status');
    }

    public function testTheDateRangeSelectsByCreationInstant(): void
    {
        $before = $this->createTask();
        $this->setCreatedAt($before, '2026-01-01 23:59:59');
        $inside = $this->createTask();
        $this->setCreatedAt($inside, '2026-01-02 12:00:00');
        $after = $this->createTask();
        $this->setCreatedAt($after, '2026-01-03 00:00:01');

        $this->client->request('GET', '/api/tasks?createdFrom=2026-01-02&createdTo=2026-01-03T00:00:00Z');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame([$inside], array_column($this->responseBody()['items'], 'task_id'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badQueries(): iterable
    {
        yield 'from is not a date' => ['createdFrom=yesterday', 'createdFrom'];
        yield 'to is not a date' => ['createdTo=2026-13-45', 'createdTo'];
        yield 'range runs backwards' => ['createdFrom=2026-01-03&createdTo=2026-01-02', 'createdFrom'];
        yield 'page zero' => ['page=0', 'page'];
        yield 'page is text' => ['page=two', 'page'];
        yield 'limit zero' => ['limit=0', 'limit'];
        yield 'limit negative' => ['limit=-5', 'limit'];
    }

    #[DataProvider('badQueries')]
    public function testABadQueryStringIsAValidationProblem(string $query, string $field): void
    {
        $this->client->request('GET', '/api/tasks?'.$query);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertProblemWithViolationOn($field);
    }

    public function testPagesAreCutAtTheLimitAndLinkedToEachOther(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->createTask();
        }

        $this->client->request('GET', '/api/tasks?limit=2&page=2');

        $this->assertStatus(Response::HTTP_OK);
        $body = $this->responseBody();

        self::assertCount(2, $body['items']);
        self::assertSame(5, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['limit']);
        self::assertSame(3, $body['pages']);
        self::assertTrue($body['hasNext']);

        $link = (string) $this->client->getResponse()->headers->get('Link');
        self::assertStringContainsString('<http://localhost/api/tasks?limit=2&page=1>; rel="prev"', $link);
        self::assertStringContainsString('<http://localhost/api/tasks?limit=2&page=3>; rel="next"', $link);

        // The three pages together are every task, each exactly once.
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $this->client->request('GET', '/api/tasks?limit=2&page='.$page);
            $seen = [...$seen, ...array_column($this->responseBody()['items'], 'task_id')];
        }
        sort($seen);
        sort($ids);
        self::assertSame($ids, $seen);
    }

    public function testTheLinksKeepTheFilters(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->createTask();
        }

        $this->client->request('GET', '/api/tasks?status=pending&limit=2');

        $link = (string) $this->client->getResponse()->headers->get('Link');
        self::assertSame('<http://localhost/api/tasks?status=pending&limit=2&page=2>; rel="next"', $link);
    }

    /** "As many as possible" is answered with the ceiling; the response says what was applied. */
    public function testALimitAboveTheCeilingIsCappedNotRefused(): void
    {
        $this->client->request('GET', '/api/tasks?limit=1000');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame(100, $this->responseBody()['limit']);
    }

    public function testAPageBeyondTheEndIsEmptyWithOnlyAPrevLink(): void
    {
        $this->createTask();

        $this->client->request('GET', '/api/tasks?page=9');

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame([], $this->responseBody()['items']);
        self::assertSame(1, $this->responseBody()['total']);
        self::assertFalse($this->responseBody()['hasNext']);
        self::assertStringNotContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));
        self::assertStringContainsString('rel="prev"', (string) $this->client->getResponse()->headers->get('Link'));
    }

    private function assertProblemWithViolationOn(string $field): void
    {
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client->getResponse()->headers->get('Content-Type'));

        $body = $this->responseBody();

        self::assertSame(400, $body['status']);
        self::assertArrayHasKey($field, $body['violations']);
        self::assertSame([], $this->transport('async')->getSent());
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

    private function setCreatedAt(string $taskId, string $createdAt): void
    {
        $this->connection()->executeStatement('UPDATE video_tasks SET created_at = ? WHERE id = ?', [$createdAt, $taskId]);
    }
}
