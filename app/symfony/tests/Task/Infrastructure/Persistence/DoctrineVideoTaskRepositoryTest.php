<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

final class DoctrineVideoTaskRepositoryTest extends DatabaseTestCase
{
    private VideoTaskRepository $repository;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(VideoTaskRepository::class);
        self::assertInstanceOf(VideoTaskRepository::class, $repository);
        $this->repository = $repository;

        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testATaskSurvivesARoundTrip(): void
    {
        $task = VideoTask::create(['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]], $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame($task->id()->value, $loaded->id()->value);
        self::assertSame($task->payload(), $loaded->payload());
        self::assertSame(VideoTaskStatus::PENDING, $loaded->status());
        self::assertTrue($this->now->equals($loaded->createdAt()));
    }

    public function testAnUnknownIdReadsBackAsNothing(): void
    {
        self::assertNull($this->repository->get(UuidValue::new()));
    }

    public function testUpdatesAreWrittenBack(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost/videos/final.mp4', $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::COMPLETED, $loaded->status());
        self::assertSame('http://localhost/videos/final.mp4', $loaded->finalVideoUrl());
    }

    /**
     * The point of the conditional UPDATE: read-then-write would let two
     * workers handed the same message both see "pending" and both start.
     */
    public function testOnlyTheFirstClaimSucceeds(): void
    {
        $task = $this->storedTask();

        self::assertTrue($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
        self::assertFalse($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    public function testClaimingWritesTheProcessingStatus(): void
    {
        $task = $this->storedTask();

        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::PROCESSING, $loaded->status());
    }

    /**
     * The statement goes straight to the connection, so an instance the unit of
     * work is already holding would keep reporting the old status and write it
     * back on the next flush.
     */
    public function testAnAlreadyLoadedTaskSeesTheClaim(): void
    {
        $task = $this->storedTask();
        $this->repository->get($task->id());

        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame(VideoTaskStatus::PROCESSING, $loaded->status());
    }

    public function testAnUnknownTaskCannotBeClaimed(): void
    {
        self::assertFalse($this->repository->claimForProcessing(UuidValue::new(), $this->now, $this->staleBefore()));
    }

    public function testACompletedTaskCannotBeClaimed(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost/videos/final.mp4', $this->now);
        $this->repository->save($task);

        self::assertFalse($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    /** So that `messenger:failed:retry` is not a no-op. */
    public function testAFailedTaskCanBeClaimedAgain(): void
    {
        $task = $this->storedTask();
        $task->markFailed('boom', $this->now);
        $this->repository->save($task);

        self::assertTrue($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    /** A worker killed mid-task must not strand it in "processing" for ever. */
    public function testAClaimOlderThanTheLeaseCanBeTakenOver(): void
    {
        $task = $this->storedTask();
        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        // Ten minutes into a one-hour lease: the task still belongs to whoever
        // took it.
        $soon = $this->now->minusSeconds(-600);
        self::assertFalse(
            $this->repository->claimForProcessing($task->id(), $soon, $soon->minusSeconds(3600)),
        );

        // Two hours in, with the same lease: the claim is abandoned.
        $muchLater = $this->now->minusSeconds(-7200);
        self::assertTrue(
            $this->repository->claimForProcessing($task->id(), $muchLater, $muchLater->minusSeconds(3600)),
        );
    }

    public function testReleasingMakesTheTaskClaimableAgain(): void
    {
        $task = $this->storedTask();
        $this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore());

        self::assertTrue($this->repository->release($task->id(), $this->now));
        self::assertTrue($this->repository->claimForProcessing($task->id(), $this->now, $this->staleBefore()));
    }

    public function testReleasingATaskNobodyClaimedChangesNothing(): void
    {
        $task = $this->storedTask();

        self::assertFalse($this->repository->release($task->id(), $this->now));
    }

    /**
     * The enum is stored as its own value; a row written with anything else is a
     * corrupted row and has to say so rather than being read as some default.
     */
    public function testAnUnknownStatusInTheDatabaseIsRefused(): void
    {
        $task = $this->storedTask();
        $this->connection()->executeStatement('UPDATE video_tasks SET status = ? WHERE id = ?', ['exploded', $task->id()->value]);
        $this->entityManager->clear();

        $this->expectException(\ValueError::class);

        $this->repository->get($task->id());
    }

    /** MySQL enforces the column widths that SQLite quietly ignores. */
    #[Group('mysql')]
    public function testAUrlUpToTheColumnWidthIsStored(): void
    {
        $url = 'http://localhost/videos/'.str_repeat('a', 2000).'.mp4';

        $task = $this->storedTask();
        $task->markProcessing($this->now);
        $task->markCompleted($url, $this->now);
        $this->repository->save($task);
        $this->entityManager->clear();

        $loaded = $this->repository->get($task->id());

        self::assertNotNull($loaded);
        self::assertSame($url, $loaded->finalVideoUrl());
    }

    private function storedTask(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->repository->save($task);

        return $task;
    }

    private function staleBefore(): DateTimeValue
    {
        return $this->now->minusSeconds(3600);
    }
}
