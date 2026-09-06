<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Callback;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Domain\Entity\VideoTask;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

final class TaskCallbacksTest extends TestCase
{
    private RecordingMessageBus $bus;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        $this->bus = new RecordingMessageBus();
        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testATaskWithACallbackUrlQueuesANotificationOfItsStatus(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->cancel($this->now);

        new TaskCallbacks($this->bus)->notify($task);

        self::assertCount(1, $this->bus->dispatched);
        $message = $this->bus->dispatched[0];
        self::assertInstanceOf(NotifyTaskCallback::class, $message);
        self::assertSame($task->id()->value, $message->taskId);
        self::assertSame('canceled', $message->event);
    }

    public function testATaskWithoutACallbackUrlQueuesNothing(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);

        new TaskCallbacks($this->bus)->notify($task);

        self::assertSame([], $this->bus->dispatched);
    }
}
