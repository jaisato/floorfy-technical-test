<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\ReadModel;

use App\Task\Application\DTO\VideoTaskSummaryView;
use App\Task\Application\ReadModel\TaskPage;
use App\Task\Domain\ValueObject\TaskProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskPageTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int, bool}> */
    public static function pagings(): iterable
    {
        // total, page, limit, expected pages, expected hasNext
        yield 'nothing to list' => [0, 1, 20, 0, false];
        yield 'less than one page' => [5, 1, 20, 1, false];
        yield 'exactly one page' => [20, 1, 20, 1, false];
        yield 'one more than a page' => [21, 1, 20, 2, true];
        yield 'last of several' => [21, 2, 20, 2, false];
        yield 'beyond the last' => [21, 3, 20, 2, false];
    }

    #[DataProvider('pagings')]
    public function testPagesAndHasNextFollowFromTheTotalAndTheLimit(int $total, int $page, int $limit, int $pages, bool $hasNext): void
    {
        $result = new TaskPage([], $total, $page, $limit);

        self::assertSame($pages, $result->pages);
        self::assertSame($hasNext, $result->hasNext);
    }

    public function testItSerialisesItemsAndTheFiguresAClientPagesOn(): void
    {
        $item = new VideoTaskSummaryView(
            '0195c6a0-1c37-7000-8000-000000000001',
            'pending',
            TaskProgress::ofCounts(0, 0, 2),
            null,
            null,
            '2026-01-02T03:04:05+00:00',
            '2026-01-02T03:04:05+00:00',
        );

        $page = new TaskPage([$item], 41, 2, 20);

        self::assertSame([
            'items' => [$item->toArray()],
            'total' => 41,
            'page' => 2,
            'limit' => 20,
            'pages' => 3,
            'hasNext' => true,
        ], $page->toArray());
    }

    public function testAnImpossiblePageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskPage([], 10, 0, 20);
    }
}
