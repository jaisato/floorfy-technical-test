<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Callback;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Domain\Entity\VideoTask;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

final class TaskCallbacksTest extends TestCase
{
    private RecordingMessageBus $bus;
    private InMemoryVideoTaskRepository $tasks;
    private FixedClock $clock;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        $this->bus = new RecordingMessageBus();
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
        $this->clock = new FixedClock($this->now->toIso8601());
    }

    private function callbacks(): TaskCallbacks
    {
        return new TaskCallbacks($this->bus, $this->tasks, $this->clock);
    }

    public function testATaskWithACallbackUrlQueuesANotificationOfItsStatus(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->cancel($this->now);

        $this->callbacks()->notify($task, 7);

        self::assertCount(1, $this->bus->dispatched);
        $message = $this->bus->dispatched[0];
        self::assertInstanceOf(NotifyTaskCallback::class, $message);
        self::assertSame($task->id()->value, $message->taskId);
        self::assertSame('canceled', $message->event);
        self::assertSame(7, $message->generation, 'the run that settled, so the delivery is recorded against it');
    }

    /**
     * And stamps the attempt, so the recovery sweep counts the notification as
     * published rather than lost.
     *
     * The sweep offers a settled task whose callback was never delivered and
     * whose last attempt is older than the cutoff. With nothing stamped here
     * the only date it had was the one the task settled at, so with a
     * ten-minute window and a transport that retries a callback for about a
     * quarter of an hour, it published a second notification at minute ten on
     * top of the one still being retried and the client got the event twice.
     */
    public function testTheNotificationIsStampedSoTheSweepDoesNotPublishASecond(): void
    {
        $task = VideoTask::create(['images' => []], $this->now->minusSeconds(3600), 'https://client.example/hook');
        $task->markProcessing($this->now->minusSeconds(3600));
        $task->markCompleted('/videos/final.mp4', $this->now->minusSeconds(3600));
        $this->tasks->save($task);

        // Settled an hour ago, so a ten-minute sweep would otherwise take it.
        $cutoff = $this->now->minusSeconds(600);
        self::assertCount(1, $this->tasks->listAwaitingCallback($cutoff, 10), 'precondition');

        $this->callbacks()->notify($task, $task->runGeneration());

        self::assertCount(1, $this->bus->dispatched);
        self::assertSame([], $this->tasks->listAwaitingCallback($cutoff, 10), 'the sweep leaves it to the transport');
    }

    public function testATaskWithoutACallbackUrlQueuesNothing(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);

        $this->callbacks()->notify($task, 7);

        self::assertSame([], $this->bus->dispatched);
    }
}
