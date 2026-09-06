<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Response;

use App\Task\Application\ReadModel\TaskPage;
use App\Ui\Http\Response\PageResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PageResponseTest extends TestCase
{
    public function testTheFirstOfSeveralPagesLinksOnlyForward(): void
    {
        $request = Request::create('http://localhost:8080/api/tasks?status=failed&page=1&limit=20');

        self::assertSame(
            ['<http://localhost:8080/api/tasks?status=failed&page=2&limit=20>; rel="next"'],
            PageResponse::links($request, new TaskPage([], 50, 1, 20)),
        );
    }

    public function testAMiddlePageLinksBothWays(): void
    {
        $request = Request::create('http://localhost:8080/api/tasks?page=2');

        self::assertSame(
            [
                '<http://localhost:8080/api/tasks?page=1>; rel="prev"',
                '<http://localhost:8080/api/tasks?page=3>; rel="next"',
            ],
            PageResponse::links($request, new TaskPage([], 50, 2, 20)),
        );
    }

    public function testTheLastPageLinksOnlyBackAndASinglePageNotAtAll(): void
    {
        self::assertSame(
            ['<http://localhost:8080/api/tasks?page=2>; rel="prev"'],
            PageResponse::links(Request::create('http://localhost:8080/api/tasks?page=3'), new TaskPage([], 50, 3, 20)),
        );

        self::assertSame([], PageResponse::links(Request::create('http://localhost:8080/api/tasks'), new TaskPage([], 5, 1, 20)));
    }

    /** The filters travel with the links, encoded so a zone offset survives. */
    public function testEveryFilterIsKeptAndEncoded(): void
    {
        $request = Request::create('http://localhost:8080/api/tasks?createdFrom=2026-01-02T00%3A00%3A00%2B01%3A00&limit=5');

        $links = PageResponse::links($request, new TaskPage([], 50, 1, 5));

        self::assertSame(
            ['<http://localhost:8080/api/tasks?createdFrom=2026-01-02T00%3A00%3A00%2B01%3A00&limit=5&page=2>; rel="next"'],
            $links,
        );
    }

    public function testTheResponseCarriesTheBodyAndTheHeader(): void
    {
        $response = PageResponse::serve(Request::create('http://localhost:8080/api/tasks'), new TaskPage([], 50, 1, 20));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('rel="next"', (string) $response->headers->get('Link'));
        self::assertSame(
            ['items' => [], 'total' => 50, 'page' => 1, 'limit' => 20, 'pages' => 3, 'hasNext' => true],
            json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }
}
