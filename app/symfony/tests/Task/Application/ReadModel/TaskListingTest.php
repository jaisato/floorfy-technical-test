<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\ReadModel;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\ReadModel\TaskListing;
use App\Task\Domain\Enum\VideoTaskStatus;
use PHPUnit\Framework\TestCase;

final class TaskListingTest extends TestCase
{
    public function testTheDefaultIsTheFirstPageOfTwentyWithNoFilter(): void
    {
        $listing = new TaskListing();

        self::assertNull($listing->status);
        self::assertNull($listing->createdFrom);
        self::assertNull($listing->createdTo);
        self::assertSame(1, $listing->page);
        self::assertSame(TaskListing::DEFAULT_LIMIT, $listing->limit);
        self::assertSame(0, $listing->offset());
    }

    public function testTheOffsetSkipsThePreviousPages(): void
    {
        self::assertSame(40, new TaskListing(page: 3, limit: 20)->offset());
    }

    public function testThePageMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskListing(page: 0);
    }

    public function testTheLimitMustStayInsideTheCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskListing(limit: TaskListing::MAX_LIMIT + 1);
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskListing(limit: 0);
    }

    public function testTheDateRangeMustRunForwards(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskListing(
            createdFrom: DateTimeValue::fromString('2026-01-03T00:00:00Z'),
            createdTo: DateTimeValue::fromString('2026-01-02T00:00:00Z'),
        );
    }

    public function testAStatusFilterAndARangeAreKept(): void
    {
        $from = DateTimeValue::fromString('2026-01-01T00:00:00Z');
        $to = DateTimeValue::fromString('2026-01-31T23:59:59Z');

        $listing = new TaskListing(VideoTaskStatus::FAILED, $from, $to, 2, 50);

        self::assertSame(VideoTaskStatus::FAILED, $listing->status);
        self::assertSame($from, $listing->createdFrom);
        self::assertSame($to, $listing->createdTo);
        self::assertSame(50, $listing->offset());
    }
}
