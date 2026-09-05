<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

final class DoctrinePartialVideoRepositoryTest extends DatabaseTestCase
{
    private PartialVideoRepository $partials;
    private VideoTaskRepository $tasks;
    private DateTimeValue $now;
    private VideoTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $partials = self::getContainer()->get(PartialVideoRepository::class);
        self::assertInstanceOf(PartialVideoRepository::class, $partials);
        $this->partials = $partials;

        $tasks = self::getContainer()->get(VideoTaskRepository::class);
        self::assertInstanceOf(VideoTaskRepository::class, $tasks);
        $this->tasks = $tasks;

        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
        $this->task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($this->task);
    }

    public function testAPartSurvivesARoundTrip(): void
    {
        $partial = $this->partial(0, 'https://example.com/a.png', Transition::ZOOM_OUT);
        $partial->markFailed('la descarga falló', $this->now);
        $this->partials->save($partial);
        $this->entityManager->clear();

        $loaded = $this->partials->listByTaskId($this->task->id())[0];

        self::assertSame($partial->id()->value, $loaded->id()->value);
        self::assertSame($this->task->id()->value, $loaded->taskId()->value);
        self::assertSame('https://example.com/a.png', $loaded->imageUrl());
        self::assertSame(Transition::ZOOM_OUT, $loaded->transition());
        self::assertSame(PartialVideoStatus::FAILED, $loaded->status());
        self::assertSame('la descarga falló', $loaded->errorMessage());
    }

    /**
     * Every part of a task is created in the same request and created_at has
     * one-second resolution, so ordering by it left the concatenation order to
     * whatever the database happened to return.
     */
    public function testPartsComeBackInPlaybackOrderRegardlessOfInsertOrder(): void
    {
        $this->partials->saveAll([
            $this->partial(2, 'https://example.com/c.png'),
            $this->partial(0, 'https://example.com/a.png'),
            $this->partial(1, 'https://example.com/b.png'),
        ]);
        $this->entityManager->clear();

        self::assertSame(
            ['https://example.com/a.png', 'https://example.com/b.png', 'https://example.com/c.png'],
            array_map(
                static fn (PartialVideo $p): string => $p->imageUrl(),
                $this->partials->listByTaskId($this->task->id()),
            ),
        );
    }

    public function testPartsOfOtherTasksAreNotReturned(): void
    {
        $other = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($other);

        $this->partials->saveAll([
            $this->partial(0, 'https://example.com/mine.png'),
            PartialVideo::create($other->id(), 'https://example.com/theirs.png', Transition::PAN, 0, $this->now),
        ]);
        $this->entityManager->clear();

        $mine = $this->partials->listByTaskId($this->task->id());

        self::assertCount(1, $mine);
        self::assertSame('https://example.com/mine.png', $mine[0]->imageUrl());
    }

    public function testATaskWithNoPartsReadsBackEmpty(): void
    {
        self::assertSame([], $this->partials->listByTaskId(UuidValue::new()));
    }

    public function testUpdatesAreWrittenBack(): void
    {
        $partial = $this->partial(0, 'https://example.com/a.png');
        $this->partials->save($partial);

        $partial->markCompleted('/videos/partial_x.mp4', $this->now);
        $this->partials->save($partial);
        $this->entityManager->clear();

        $loaded = $this->partials->listByTaskId($this->task->id())[0];

        self::assertSame(PartialVideoStatus::COMPLETED, $loaded->status());
        self::assertSame('/videos/partial_x.mp4', $loaded->videoPath());
    }

    /**
     * Nothing stopped a part pointing at a task that does not exist, and
     * deleting a task left its parts behind for good. SQLite has foreign keys
     * off by default, so this is MySQL's to prove.
     */
    #[Group('mysql')]
    public function testDeletingATaskTakesItsPartsWithIt(): void
    {
        $this->partials->save($this->partial(0, 'https://example.com/a.png'));
        $this->connection()->executeStatement('DELETE FROM video_tasks WHERE id = ?', [$this->task->id()->value]);

        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM partial_videos WHERE task_id = ?',
            [$this->task->id()->value],
        ));
    }

    #[Group('mysql')]
    public function testAPartCannotPointAtATaskThatDoesNotExist(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection()->executeStatement(
            'INSERT INTO partial_videos (id, task_id, image_url, transition, position, status, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                UuidValue::new()->value,
                UuidValue::new()->value,
                'https://example.com/a.png',
                'pan',
                0,
                'pending',
                '2026-01-02 03:04:05',
                '2026-01-02 03:04:05',
            ],
        );
    }

    #[Group('mysql')]
    public function testAnImageUrlUpToTheColumnWidthIsStored(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 2028);

        $this->partials->save($this->partial(0, $url));
        $this->entityManager->clear();

        self::assertSame($url, $this->partials->listByTaskId($this->task->id())[0]->imageUrl());
    }

    private function partial(int $position, string $url, Transition $transition = Transition::PAN): PartialVideo
    {
        return PartialVideo::create($this->task->id(), $url, $transition, $position, $this->now);
    }
}
