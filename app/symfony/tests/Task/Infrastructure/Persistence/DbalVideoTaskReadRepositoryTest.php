<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\DTO\VideoTaskSummaryView;
use App\Task\Application\Query\GetVideoTaskHandler;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Task\Application\ReadModel\TaskListing;
use App\Task\Application\ReadModel\VideoTaskReadRepository;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Tests\Support\DatabaseTestCase;

/**
 * The listing is SQL of its own, so what it returns is pinned against rows the
 * write side stored: order, filters, paging figures and the part counts.
 */
final class DbalVideoTaskReadRepositoryTest extends DatabaseTestCase
{
    private VideoTaskReadRepository $listing;
    private VideoTaskRepository $tasks;
    private PartialVideoRepository $partials;

    protected function setUp(): void
    {
        parent::setUp();

        $listing = self::getContainer()->get(VideoTaskReadRepository::class);
        self::assertInstanceOf(VideoTaskReadRepository::class, $listing);
        $this->listing = $listing;

        $tasks = self::getContainer()->get(VideoTaskRepository::class);
        self::assertInstanceOf(VideoTaskRepository::class, $tasks);
        $this->tasks = $tasks;

        $partials = self::getContainer()->get(PartialVideoRepository::class);
        self::assertInstanceOf(PartialVideoRepository::class, $partials);
        $this->partials = $partials;
    }

    public function testAnEmptyTableIsAnEmptyFirstPage(): void
    {
        $page = $this->listing->list(new TaskListing());

        self::assertSame([], $page->items);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->pages);
        self::assertFalse($page->hasNext);
    }

    public function testTasksComeNewestFirst(): void
    {
        $this->storedTask('2026-01-01T10:00:00Z');
        $newest = $this->storedTask('2026-01-03T10:00:00Z');
        $middle = $this->storedTask('2026-01-02T10:00:00Z');

        $page = $this->listing->list(new TaskListing());

        self::assertSame(
            [$newest->id()->value, $middle->id()->value],
            \array_slice(self::ids($page->items), 0, 2),
        );
    }

    /**
     * Every task of a burst is created in the same second, so created_at alone
     * cannot separate them; the id (time-ordered) breaks the tie the same way
     * every time, and two pages never show the same task or skip one.
     */
    public function testTasksCreatedInTheSameSecondHaveAStableOrder(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->storedTask('2026-01-02T10:00:00Z');
        }

        $first = self::ids($this->listing->list(new TaskListing(page: 1, limit: 2))->items);
        $second = self::ids($this->listing->list(new TaskListing(page: 2, limit: 2))->items);
        $third = self::ids($this->listing->list(new TaskListing(page: 3, limit: 2))->items);

        $seen = [...$first, ...$second, ...$third];

        self::assertCount(5, $seen);
        self::assertSame($seen, array_unique($seen));
        self::assertSame($seen, self::ids($this->listing->list(new TaskListing(limit: 10))->items));
    }

    public function testThePagingFiguresDescribeTheWholeResult(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->storedTask('2026-01-02T10:00:00Z');
        }

        $page = $this->listing->list(new TaskListing(page: 2, limit: 2));

        self::assertCount(2, $page->items);
        self::assertSame(5, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->limit);
        self::assertSame(3, $page->pages);
        self::assertTrue($page->hasNext);

        $last = $this->listing->list(new TaskListing(page: 3, limit: 2));

        self::assertCount(1, $last->items);
        self::assertFalse($last->hasNext);
    }

    public function testAPageBeyondTheEndIsEmptyButKeepsTheTotal(): void
    {
        $this->storedTask('2026-01-02T10:00:00Z');

        $page = $this->listing->list(new TaskListing(page: 4, limit: 20));

        self::assertSame([], $page->items);
        self::assertSame(1, $page->total);
        self::assertSame(1, $page->pages);
        self::assertFalse($page->hasNext);
    }

    public function testTheStatusFilterKeepsOnlyThatStatus(): void
    {
        $this->storedTask('2026-01-02T10:00:00Z');
        $failed = $this->storedTask('2026-01-02T11:00:00Z');
        $failed->markFailed('boom', DateTimeValue::fromString('2026-01-02T11:00:00Z'));
        $this->tasks->save($failed);

        $page = $this->listing->list(new TaskListing(status: VideoTaskStatus::FAILED));

        self::assertSame([$failed->id()->value], self::ids($page->items));
        self::assertSame(1, $page->total);
        self::assertSame('failed', $page->items[0]->status);
        self::assertSame('boom', $page->items[0]->error);
    }

    /** Both bounds are inclusive, so a range can name the exact second a task was created. */
    public function testTheDateRangeIsInclusiveAtBothEnds(): void
    {
        $before = $this->storedTask('2026-01-01T23:59:59Z');
        $atStart = $this->storedTask('2026-01-02T00:00:00Z');
        $inside = $this->storedTask('2026-01-02T12:00:00Z');
        $atEnd = $this->storedTask('2026-01-03T00:00:00Z');
        $after = $this->storedTask('2026-01-03T00:00:01Z');

        $page = $this->listing->list(new TaskListing(
            createdFrom: DateTimeValue::fromString('2026-01-02T00:00:00Z'),
            createdTo: DateTimeValue::fromString('2026-01-03T00:00:00Z'),
        ));

        $ids = self::ids($page->items);
        sort($ids);
        $expected = [$atStart->id()->value, $inside->id()->value, $atEnd->id()->value];
        sort($expected);

        self::assertSame($expected, $ids);
        self::assertNotContains($before->id()->value, $ids);
        self::assertNotContains($after->id()->value, $ids);
    }

    public function testEitherBoundWorksOnItsOwn(): void
    {
        $old = $this->storedTask('2026-01-01T10:00:00Z');
        $recent = $this->storedTask('2026-01-05T10:00:00Z');

        $from = $this->listing->list(new TaskListing(createdFrom: DateTimeValue::fromString('2026-01-03T00:00:00Z')));
        $to = $this->listing->list(new TaskListing(createdTo: DateTimeValue::fromString('2026-01-03T00:00:00Z')));

        self::assertSame([$recent->id()->value], self::ids($from->items));
        self::assertSame([$old->id()->value], self::ids($to->items));
    }

    /** A range given in another zone selects the same instants as its UTC equivalent. */
    public function testTheRangeIsComparedAsInstants(): void
    {
        $task = $this->storedTask('2026-01-02T10:00:00Z');

        $page = $this->listing->list(new TaskListing(
            createdFrom: DateTimeValue::fromString('2026-01-02T11:00:00+01:00'),
            createdTo: DateTimeValue::fromString('2026-01-02T11:00:00+01:00'),
        ));

        self::assertSame([$task->id()->value], self::ids($page->items));
    }

    public function testEachItemCountsItsOwnParts(): void
    {
        $now = DateTimeValue::fromString('2026-01-02T10:00:00Z');
        $task = $this->storedTask('2026-01-02T10:00:00Z');
        $other = $this->storedTask('2026-01-02T09:00:00Z');

        $done = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $now);
        $done->markCompleted('/videos/partial_a.mp4', $now);
        $broken = PartialVideo::create($task->id(), 'https://example.com/b.png', Transition::PAN, 1, $now);
        $broken->markFailed('la descarga falló', $now);
        $waiting = PartialVideo::create($task->id(), 'https://example.com/c.png', Transition::PAN, 2, $now);
        $theirs = PartialVideo::create($other->id(), 'https://example.com/d.png', Transition::PAN, 0, $now);
        $this->partials->saveAll([$done, $broken, $waiting, $theirs]);

        $items = $this->listing->list(new TaskListing())->items;

        self::assertSame(
            ['completed' => 1, 'failed' => 1, 'pending' => 1, 'total' => 3, 'percent' => 33],
            $items[0]->progress->toArray(),
        );
        self::assertSame(
            ['completed' => 0, 'failed' => 0, 'pending' => 1, 'total' => 1, 'percent' => 0],
            $items[1]->progress->toArray(),
        );
    }

    public function testATaskWithoutPartsIsAtZero(): void
    {
        $this->storedTask('2026-01-02T10:00:00Z');

        self::assertSame(0, $this->listing->list(new TaskListing())->items[0]->progress->total);
    }

    /**
     * The listing is its own SQL; the single-task view is built from entities.
     * A client must see the same summary whichever way it asked.
     */
    public function testAnItemIsTheSameSummaryTheSingleTaskViewShows(): void
    {
        $now = DateTimeValue::fromString('2026-01-02T10:00:00Z');
        $task = $this->storedTask('2026-01-02T10:00:00Z');
        $task->markProcessing($now);
        $task->markCompleted('/videos/final.mp4', DateTimeValue::fromString('2026-01-02T10:05:00Z'));
        $this->tasks->save($task);

        $part = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $now);
        $part->markCompleted('/videos/partial_a.mp4', $now);
        $this->partials->save($part);
        $this->entityManager->clear();

        $single = new GetVideoTaskHandler($this->tasks, $this->partials, self::urls())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($single);
        self::assertSame($single->summary->toArray(), $this->listing->list(new TaskListing())->items[0]->toArray());
        self::assertSame('2026-01-02T10:05:00+00:00', $single->summary->updatedAt);
    }

    /** The same URLs the container's read repository builds: base URL, unsigned. */
    private static function urls(): VideoUrls
    {
        $urls = self::getContainer()->get(VideoUrls::class);

        self::assertInstanceOf(VideoUrls::class, $urls);

        return $urls;
    }

    private function storedTask(string $createdAt): VideoTask
    {
        $task = VideoTask::create(['images' => []], DateTimeValue::fromString($createdAt));
        $this->tasks->save($task);

        return $task;
    }

    /**
     * @param list<VideoTaskSummaryView> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn (VideoTaskSummaryView $item): string => $item->taskId, $items);
    }
}
