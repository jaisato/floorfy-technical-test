<?php

declare(strict_types=1);

namespace App\Tests\Task\Domain\ValueObject;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\ValueObject\TaskProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskProgressTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int}> */
    public static function counts(): iterable
    {
        // completed, failed, pending, expected percent
        yield 'nothing done' => [0, 0, 4, 0];
        yield 'one of three' => [1, 0, 2, 33];
        yield 'two of three' => [2, 0, 1, 66];
        yield 'all done' => [3, 0, 0, 100];
        yield 'half done, half failed' => [2, 2, 0, 50];
        yield 'one of two, other failed' => [1, 1, 0, 50];
        yield 'nineteen of twenty' => [19, 0, 1, 95];
    }

    #[DataProvider('counts')]
    public function testThePercentageIsTheFlooredShareOfCompletedParts(int $completed, int $failed, int $pending, int $percent): void
    {
        $progress = TaskProgress::ofCounts($completed, $failed, $pending);

        self::assertSame($percent, $progress->percent);
        self::assertSame($completed + $failed + $pending, $progress->total);
    }

    /** A task with no parts cannot be divided by; it simply has not progressed. */
    public function testATaskWithoutPartsIsAtZero(): void
    {
        self::assertSame(
            ['completed' => 0, 'failed' => 0, 'pending' => 0, 'total' => 0, 'percent' => 0],
            TaskProgress::ofCounts(0, 0, 0)->toArray(),
        );
    }

    /** 100 is reserved for "every part is done": 199 of 200 must read 99. */
    public function testItNeverRoundsUpToOneHundred(): void
    {
        self::assertSame(99, TaskProgress::ofCounts(199, 0, 1)->percent);
    }

    public function testItIsCountedOverTheParts(): void
    {
        $now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
        $taskId = UuidValue::new();

        $done = PartialVideo::create($taskId, 'https://example.com/a.png', Transition::PAN, 0, $now);
        $done->markCompleted('/videos/partial_a.mp4', $now);

        $broken = PartialVideo::create($taskId, 'https://example.com/b.png', Transition::PAN, 1, $now);
        $broken->markFailed('la descarga falló', $now);

        $waiting = PartialVideo::create($taskId, 'https://example.com/c.png', Transition::PAN, 2, $now);

        self::assertSame(
            ['completed' => 1, 'failed' => 1, 'pending' => 1, 'total' => 3, 'percent' => 33],
            TaskProgress::ofParts([$done, $broken, $waiting])->toArray(),
        );
    }

    public function testNegativeCountsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaskProgress::ofCounts(1, -1, 0);
    }
}
