<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Callback;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Callback\CallbackDeliveryFailed;
use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\NotifyTaskCallbackHandler;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Task\Infrastructure\Media\BlockedUrl;
use App\Tests\Support\FakeCallbackDelivery;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class NotifyTaskCallbackHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private FakeCallbackDelivery $delivery;
    private RecordingLogger $logger;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->delivery = new FakeCallbackDelivery();
        $this->logger = new RecordingLogger();
        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testItDeliversTheCurrentSummaryToTheTasksCallbackUrl(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);
        $this->tasks->save($task);

        $part = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->now);
        $part->markCompleted('/videos/partial_a.mp4', $this->now);
        $this->partials->save($part);

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));

        self::assertCount(1, $this->delivery->delivered);
        $request = $this->delivery->delivered[0];
        self::assertSame('https://client.example/hook', $request->url);
        self::assertSame($task->id()->value, $request->taskId);
        self::assertSame('completed', $request->event);
        self::assertSame('completed', $request->body['status']);
        self::assertSame('http://localhost:8080/videos/final.mp4', $request->body['final_video_url']);
        self::assertSame(['completed' => 1, 'failed' => 0, 'pending' => 0, 'total' => 1, 'percent' => 100], $request->body['progress']);
        self::assertArrayNotHasKey('partial_videos', $request->body, 'the body is the summary, not the full view');
    }

    /** The event is what happened; the body is how the task is now - the two can differ after a retry. */
    public function testTheEventTravelsSeparatelyFromTheCurrentStatus(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markFailed('boom', $this->now);
        $task->retry($this->now);
        $this->tasks->save($task);

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'failed', VideoTask::FIRST_RUN));

        self::assertSame('failed', $this->delivery->delivered[0]->event);
        self::assertSame('pending', $this->delivery->delivered[0]->body['status']);
    }

    /**
     * The delivery takes as long as the endpoint takes, and a retry landing in
     * that window re-renders and settles again. Marked all the same, the
     * notification of the run that is over answered for the run that is not:
     * the recovery sweep looks for settled tasks whose callback never went
     * out, this row said it had, and the client was never told the retry
     * completed.
     *
     * The second run here ends the *same way* as the first, which is what the
     * status half of the fence cannot see: both say "failed", and the run the
     * notification announced is only told apart by the generation.
     */
    public function testADeliveryOvertakenByARetryDoesNotAnswerForTheNewRun(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markFailed('boom', $this->now);
        $this->tasks->save($task);

        // Retried and failed again while this notification was on the wire.
        $this->tasks->beforeMarkNotified = function () use ($task): void {
            $task->retry($this->now);
            $this->tasks->markFailedIfStillRunning($task->id(), 'boom again', null, $this->now);
        };

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'failed', VideoTask::FIRST_RUN));

        self::assertSame('failed', $this->tasks->get($task->id())?->status()->value, 'the two runs ended alike');
        self::assertCount(1, $this->delivery->delivered, 'the delivery itself still happened');
        self::assertArrayNotHasKey(
            $task->id()->value,
            $this->tasks->callbacksNotified,
            'and the failure the client has not heard about is still owed',
        );
    }

    /** The ordinary case: nothing moved, so the mark stands and the sweep stops offering the task. */
    public function testADeliveryOfTheCurrentStatusIsRecorded(): void
    {
        $task = $this->taskWithCallback();
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));

        self::assertArrayHasKey($task->id()->value, $this->tasks->callbacksNotified);
    }

    public function testATaskWithoutACallbackUrlIsAcknowledgedSilently(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($task);

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));

        self::assertSame([], $this->delivery->delivered);
    }

    /** Retrying cannot make a missing task appear. */
    public function testAMissingTaskIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new NotifyTaskCallback('0195c6a0-1c37-7000-8000-0000000000ff', 'completed', VideoTask::FIRST_RUN));
    }

    public function testAPermanentDeliveryFailureIsNotRetried(): void
    {
        $task = $this->taskWithCallback();
        $this->delivery->failWith(CallbackDeliveryFailed::refused('http://10.0.0.1/hook', BlockedUrl::privateAddress('client.example', '10.0.0.1')));

        try {
            $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));
            self::fail('a refused URL must be reported');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertInstanceOf(CallbackDeliveryFailed::class, $e->getPrevious());
        }

        self::assertStringContainsString('Callback delivery failed', $this->logger->everythingLogged());
    }

    /**
     * Refusing the message is only half of it. The row is left exactly as the
     * recovery sweep recognises one whose publish was lost - settled, a
     * callback asked for, none delivered - so without a record of the verdict
     * the sweep published this same doomed notification a cutoff later, and
     * again, for the life of the task. An unset signing secret signs nothing on
     * the next attempt either.
     */
    public function testAPermanentFailureIsRecordedSoTheRecoverySweepStopsOfferingIt(): void
    {
        $task = $this->settledTaskWithCallback();
        $this->delivery->failWith(CallbackDeliveryFailed::unsigned());

        try {
            $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));
            self::fail('a deployment that cannot sign must be reported');
        } catch (UnrecoverableMessageHandlingException) {
            // expected
        }

        self::assertArrayHasKey($task->id()->value, $this->tasks->callbacksAbandoned);
        self::assertArrayNotHasKey(
            $task->id()->value,
            $this->tasks->callbacksNotified,
            'given up on is not delivered',
        );
    }

    /** A 503 from the receiver propagates as is, so the transport retries it. */
    public function testATransientDeliveryFailureIsRethrownForTheTransportToRetry(): void
    {
        $task = $this->taskWithCallback();
        $this->delivery->failWith(CallbackDeliveryFailed::status('https://client.example/hook', 503));

        $this->expectException(CallbackDeliveryFailed::class);

        $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));
    }

    /** A delay is not an outcome: the notification is still owed and the sweep still owes it. */
    public function testATransientFailureGivesUpOnNothing(): void
    {
        $task = $this->settledTaskWithCallback();
        $this->delivery->failWith(CallbackDeliveryFailed::status('https://client.example/hook', 503));

        try {
            $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));
        } catch (CallbackDeliveryFailed) {
            // expected
        }

        self::assertSame([], $this->tasks->callbacksAbandoned);
    }

    /**
     * The verdict belongs to the run it was reached about. A retry that landed
     * while this delivery was being refused owes a notification of its own, and
     * that one gets its own attempt at whatever the deployment looks like then.
     */
    public function testAVerdictAboutAnEarlierRunDoesNotSilenceTheCurrentOne(): void
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $task->markFailed('boom', $this->now);
        $this->tasks->save($task);
        $this->delivery->failWith(CallbackDeliveryFailed::unsigned());

        // Retried and failed again while this notification was being refused:
        // the same outcome, so only the generation says it is another run.
        $this->tasks->beforeMarkAbandoned = function () use ($task): void {
            $task->retry($this->now);
            $this->tasks->markFailedIfStillRunning($task->id(), 'boom again', null, $this->now);
        };

        try {
            $this->handler()(new NotifyTaskCallback($task->id()->value, 'failed', VideoTask::FIRST_RUN));
            self::fail('a deployment that cannot sign must be reported');
        } catch (UnrecoverableMessageHandlingException) {
            // expected
        }

        self::assertSame([], $this->tasks->callbacksAbandoned, 'the run that is settled there is not the one refused');
    }

    /** Whatever the delivery does, the task itself is never touched. */
    public function testAFailedDeliveryLeavesTheTaskAlone(): void
    {
        $task = $this->taskWithCallback();
        $this->tasks->saves = 0;
        $this->delivery->failWith(CallbackDeliveryFailed::status('https://client.example/hook', 500));

        try {
            $this->handler()(new NotifyTaskCallback($task->id()->value, 'completed', VideoTask::FIRST_RUN));
        } catch (CallbackDeliveryFailed) {
            // expected
        }

        self::assertSame(0, $this->tasks->saves);
    }

    private function taskWithCallback(): VideoTask
    {
        $task = VideoTask::create(['images' => []], $this->now, 'https://client.example/hook');
        $this->tasks->save($task);

        return $task;
    }

    /** Settled where the notification says it is, which is what the marks are fenced on. */
    private function settledTaskWithCallback(): VideoTask
    {
        $task = $this->taskWithCallback();
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);

        return $task;
    }

    private function handler(): NotifyTaskCallbackHandler
    {
        return new NotifyTaskCallbackHandler($this->tasks, $this->partials, $this->delivery, new VideoUrls(new FixedClock(), 'http://localhost:8080', '', 3600), new FixedClock(), $this->logger);
    }
}
