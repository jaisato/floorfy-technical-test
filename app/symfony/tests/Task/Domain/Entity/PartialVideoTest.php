<?php

declare(strict_types=1);

namespace App\Tests\Task\Domain\Entity;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use PHPUnit\Framework\TestCase;

final class PartialVideoTest extends TestCase
{
    private const string CREATED = '2026-01-02T03:04:05+00:00';
    private const string LATER = '2026-01-02T03:09:05+00:00';

    public function testANewPartIsPendingAtItsPosition(): void
    {
        $partial = $this->partial(3);

        self::assertSame(PartialVideoStatus::PENDING, $partial->status());
        self::assertTrue($partial->status()->isPending());
        self::assertFalse($partial->isCompleted());
        self::assertSame(3, $partial->position());
        self::assertNull($partial->videoPath());
        self::assertNull($partial->errorMessage());
    }

    public function testCompletingRecordsThePath(): void
    {
        $partial = $this->partial();
        $partial->markCompleted('/videos/partial_x.mp4', $this->at(self::LATER));

        self::assertTrue($partial->isCompleted());
        self::assertSame('/videos/partial_x.mp4', $partial->videoPath());
        self::assertSame(self::LATER, $partial->updatedAt()->toIso8601());
    }

    /** A part that succeeds on a later attempt must not still report the old error. */
    public function testCompletingClearsAnEarlierError(): void
    {
        $partial = $this->partial();
        $partial->markFailed('la descarga falló', $this->at(self::CREATED));
        $partial->markCompleted('/videos/partial_x.mp4', $this->at(self::LATER));

        self::assertNull($partial->errorMessage());
    }

    public function testFailingRecordsTheReason(): void
    {
        $partial = $this->partial();
        $partial->markFailed('la descarga falló', $this->at(self::LATER));

        self::assertSame(PartialVideoStatus::FAILED, $partial->status());
        self::assertSame('la descarga falló', $partial->errorMessage());
        self::assertFalse($partial->isCompleted());
    }

    /**
     * Without this a single transient download error would pin the part at
     * "failed" for good, and no number of redeliveries could finish the task.
     */
    public function testAFailedPartCanBeQueuedAgain(): void
    {
        $partial = $this->partial();
        $partial->markCompleted('/videos/partial_x.mp4', $this->at(self::CREATED));
        $partial->markFailed('el fichero desapareció', $this->at(self::CREATED));
        $partial->markPending($this->at(self::LATER));

        self::assertTrue($partial->status()->isPending());
        self::assertNull($partial->videoPath());
        // The error belonged to the attempt that ended; this part is waiting
        // for the next one. Left behind, GET /api/tasks/{id} showed a pending
        // part next to the reason a previous attempt failed.
        self::assertNull($partial->errorMessage());
        self::assertSame(self::LATER, $partial->updatedAt()->toIso8601());
    }

    public function testRehydrationRestoresEveryField(): void
    {
        $partial = PartialVideo::rehydrate(
            UuidValue::fromString('0195c6a0-1c37-7000-8000-000000000001'),
            UuidValue::fromString('0195c6a0-1c37-7000-8000-000000000000'),
            'https://example.com/a.png',
            Transition::ZOOM_OUT,
            2,
            PartialVideoStatus::FAILED,
            null,
            'la descarga falló',
            $this->at(self::CREATED),
            $this->at(self::LATER),
        );

        self::assertSame('0195c6a0-1c37-7000-8000-000000000001', $partial->id()->value);
        self::assertSame('0195c6a0-1c37-7000-8000-000000000000', $partial->taskId()->value);
        self::assertSame('https://example.com/a.png', $partial->imageUrl());
        self::assertSame(Transition::ZOOM_OUT, $partial->transition());
        self::assertSame(2, $partial->position());
        self::assertSame('la descarga falló', $partial->errorMessage());
    }

    private function partial(int $position = 0): PartialVideo
    {
        return PartialVideo::create(
            UuidValue::fromString('0195c6a0-1c37-7000-8000-000000000000'),
            'https://example.com/a.png',
            Transition::PAN,
            $position,
            $this->at(self::CREATED),
        );
    }

    private function at(string $iso8601): DateTimeValue
    {
        return DateTimeValue::fromString($iso8601);
    }
}
