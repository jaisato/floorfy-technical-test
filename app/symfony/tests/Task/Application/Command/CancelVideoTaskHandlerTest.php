<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Command;

use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Command\CancelVideoTaskCommand;
use App\Task\Application\Command\CancelVideoTaskHandler;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\InvalidTaskTransition;
use App\Task\Domain\Exception\TaskNotFound;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

final class CancelVideoTaskHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private FixedClock $clock;
    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->clock = new FixedClock();
        $this->bus = new RecordingMessageBus();
    }

    public function testTheCancellationIsNotifiedToTheTasksCallbackUrl(): void
    {
        $task = VideoTask::create(['images' => []], $this->clock->now(), 'https://client.example/hook');
        $this->tasks->save($task);

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));

        self::assertCount(1, $this->bus->dispatched);
        self::assertInstanceOf(NotifyTaskCallback::class, $this->bus->dispatched[0]);
        self::assertSame('canceled', $this->bus->dispatched[0]->event);
    }

    public function testARefusedCancellationNotifiesNobody(): void
    {
        $task = VideoTask::create(['images' => []], $this->clock->now(), 'https://client.example/hook');
        $task->markFailed('boom', $this->clock->now());
        $this->tasks->save($task);

        try {
            $this->handler()(new CancelVideoTaskCommand($task->id()->value));
        } catch (InvalidTaskTransition) {
            // expected
        }

        self::assertSame([], $this->bus->dispatched);
    }

    public function testAPendingTaskIsCanceled(): void
    {
        $task = $this->storedTask();

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertTrue($task->isSettled());
    }

    /** The worker notices at its next part boundary; the status is what tells it. */
    public function testAProcessingTaskIsCanceled(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->clock->now());

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));

        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
    }

    public function testTheCancellationIsStamped(): void
    {
        $task = $this->storedTask();
        $this->clock->advance(300);

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));

        self::assertSame('2026-01-02T03:09:05+00:00', $task->updatedAt()->toIso8601());
    }

    public function testACompletedTaskCannotBeCanceled(): void
    {
        $task = $this->storedTask();
        $task->markProcessing($this->clock->now());
        $task->markCompleted('http://localhost/videos/final.mp4', $this->clock->now());

        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/"completed" -> "canceled"/');

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));
    }

    public function testAFailedTaskCannotBeCanceled(): void
    {
        $task = $this->storedTask();
        $task->markFailed('boom', $this->clock->now());

        $this->expectException(InvalidTaskTransition::class);

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));
    }

    public function testCancelingTwiceIsRefusedTheSecondTime(): void
    {
        $task = $this->storedTask();
        $this->handler()(new CancelVideoTaskCommand($task->id()->value));

        $this->expectException(InvalidTaskTransition::class);
        $this->expectExceptionMessageMatches('/"canceled" -> "canceled"/');

        $this->handler()(new CancelVideoTaskCommand($task->id()->value));
    }

    public function testAnUnknownTaskIsNotFound(): void
    {
        $this->expectException(TaskNotFound::class);

        $this->handler()(new CancelVideoTaskCommand('0195c6a0-1c37-7000-8000-0000000000ff'));
    }

    public function testAnIdThatIsNotAUuidIsNotFound(): void
    {
        $this->expectException(TaskNotFound::class);

        $this->handler()(new CancelVideoTaskCommand('not-a-uuid'));
    }

    private function storedTask(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->clock->now());
        $this->tasks->save($task);

        return $task;
    }

    private function handler(): CancelVideoTaskHandler
    {
        return new CancelVideoTaskHandler($this->tasks, $this->clock, new TaskCallbacks($this->bus));
    }
}
