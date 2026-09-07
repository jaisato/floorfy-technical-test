<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Command;

use App\Task\Application\Callback\NotifyTaskCallback;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Command\AttemptInFlight;
use App\Task\Application\Command\ProcessVideoTaskCommand;
use App\Task\Application\Command\ProcessVideoTaskHandler;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\TaskProcessingFailed;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Task\Infrastructure\Media\BlockedUrl;
use App\Tests\Support\FakeImageAnimator;
use App\Tests\Support\FakeImageFetcher;
use App\Tests\Support\FakeVideoComposer;
use App\Tests\Support\FixedClock;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingMessageBus;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessVideoTaskHandlerTest extends TestCase
{
    private const int LEASE_SECONDS = 3600;

    /** The deployment's shipped ffmpeg budgets, which the lease has to cover. */
    private const int ANIMATE_TIMEOUT_SECONDS = 600;
    private const int COMPOSE_TIMEOUT_SECONDS = 3600;

    /**
     * What a claim actually gets: the longest single ffmpeg run plus the
     * handler's slack, because the configured lease is only a floor. Shipped,
     * TASK_LEASE_SECONDS and FFMPEG_COMPOSE_TIMEOUT are the same hour.
     */
    private const int EFFECTIVE_LEASE_SECONDS = self::COMPOSE_TIMEOUT_SECONDS + 300;

    private InMemoryVideoTaskRepository $tasks;
    private AttemptInFlight $attempt;
    private InMemoryPartialVideoRepository $partials;
    private FakeImageFetcher $images;
    private FakeImageAnimator $animator;
    private FakeVideoComposer $composer;
    private FixedClock $clock;
    private RecordingMessageBus $bus;
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory('floorfy-process');
        mkdir($this->dir->file('videos'), 0o777, true);
        mkdir($this->dir->file('work'), 0o777, true);

        $this->tasks = new InMemoryVideoTaskRepository();
        $this->attempt = new AttemptInFlight();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->images = new FakeImageFetcher($this->dir->file('work'));
        $this->animator = new FakeImageAnimator();
        $this->composer = new FakeVideoComposer();
        $this->clock = new FixedClock();
        $this->bus = new RecordingMessageBus();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testItRendersEveryPartAndPublishesTheFinalVideo(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);

        $this->handle($task);

        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
        // The path, not a URL: the address is built when a client asks for it.
        self::assertSame('/videos/final_'.$task->id()->value.'.mp4', $task->finalVideoUrl());
        self::assertFileExists($this->dir->file('videos/final_'.$task->id()->value.'.mp4'));

        foreach ($this->partials->listByTaskId($task->id()) as $partial) {
            self::assertTrue($partial->isCompleted());
            self::assertSame('/videos/partial_'.$partial->id()->value.'.mp4', $partial->videoPath());
            self::assertFileExists($this->dir->file('videos/partial_'.$partial->id()->value.'.mp4'));
        }
    }

    /** Parts are concatenated in the order the client asked for. */
    public function testThePartsReachTheComposerInPlaybackOrder(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png', 'https://example.com/c.png']);

        $this->handle($task);

        $expected = array_map(
            fn (PartialVideo $p): string => $this->dir->file('videos/partial_'.$p->id()->value.'.mp4'),
            $this->partials->listByTaskId($task->id()),
        );

        self::assertSame($expected, $this->composer->lastFiles());
        self::assertCount(1, $this->composer->calls);
    }

    /**
     * Videos are staged next to their destination and moved into place with a
     * rename, so a client polling the task is never handed a half-written file.
     */
    public function testNothingIsLeftBehindInTheStagingDirectory(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);

        $this->handle($task);

        self::assertSame([], glob($this->dir->file('videos/.staging').'/*') ?: []);
        foreach ($this->animator->calls as $call) {
            self::assertStringContainsString('/.staging/', $call['output']);
        }
    }

    /** The downloaded image is scratch space, not something to keep. */
    public function testTheDownloadedImageIsRemovedOnceTheClipExists(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);

        $this->handle($task);

        self::assertSame([], glob($this->dir->file('work').'/*') ?: []);
    }

    public function testAMessageForAnUnknownTaskIsAcknowledgedAndDoesNothing(): void
    {
        $this->handler()(new ProcessVideoTaskCommand('0195c6a0-1c37-7000-8000-0000000000ff'));

        self::assertSame([], $this->composer->calls);
    }

    /** Retrying cannot make a malformed id valid, so the message is refused outright. */
    public function testAnIdThatIsNotAUuidIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new ProcessVideoTaskCommand('not-a-uuid'));
    }

    public function testATaskWithNoImagesIsUnrecoverable(): void
    {
        $task = $this->storedTask([]);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle($task);
    }

    /**
     * The claim is a conditional UPDATE, so a redelivery that arrives while
     * another worker still holds the task is told to drop it rather than
     * rendering everything a second time on top of the first.
     */
    public function testATaskAlreadyBeingProcessedIsLeftAlone(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $task->markProcessing($this->clock->now());

        $this->handle($task);

        self::assertSame([], $this->images->fetched);
        self::assertSame(VideoTaskStatus::PROCESSING, $task->status());
    }

    /** A worker that died mid-task must not strand it in "processing" for ever. */
    public function testAClaimOlderThanTheLeaseCanBeTakenOver(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $task->markProcessing($this->clock->now());

        $this->clock->advance(self::EFFECTIVE_LEASE_SECONDS + 1);
        $this->handle($task);

        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
    }

    /**
     * The claim is renewed between parts and never during one, so the longest a
     * healthy attempt goes without touching the row is one ffmpeg run - and the
     * shipped TASK_LEASE_SECONDS was the same hour as FFMPEG_COMPOSE_TIMEOUT.
     * A composition that used its whole budget therefore expired its own lease
     * as it finished, and the next delivery of any message for that task
     * re-rendered everything over a worker that was still writing the output.
     * The configured value is a floor; the claim gets whichever is longer.
     */
    public function testAnAttemptStillInsideItsComposeBudgetIsNotDeclaredAbandoned(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $task->markProcessing($this->clock->now());

        $this->clock->advance(self::COMPOSE_TIMEOUT_SECONDS + 1);
        $this->handle($task);

        self::assertSame([], $this->images->fetched, 'nothing was rendered a second time');
        self::assertSame(VideoTaskStatus::PROCESSING, $task->status(), 'the task is still the first attempt\'s');
    }

    public function testACompletedTaskIsNotProcessedAgain(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->handle($task);

        $callsAfterFirstRun = \count($this->composer->calls);
        $this->handle($task);

        self::assertCount($callsAfterFirstRun, $this->composer->calls);
        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
    }

    /**
     * The bug that made the twenty configured retries pointless: the handler
     * settled the task on the first error, and every later delivery returned
     * early. It must hand the claim back instead, and let Messenger decide.
     */
    public function testAFailedAttemptReleasesTheClaimAndRethrows(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->images->failFor('https://example.com/a.png', new \RuntimeException('connection reset'));

        try {
            $this->handle($task);
            self::fail('a failed attempt has to propagate so the transport can retry it');
        } catch (TaskProcessingFailed $e) {
            self::assertStringContainsString('1 de 1', $e->getMessage());
        }

        self::assertSame(VideoTaskStatus::PENDING, $task->status(), 'the task must be claimable again');
        self::assertNull($task->errorMessage(), 'only the terminal failure writes an error');
    }

    /**
     * One unreachable image must not cost the work already done for the others:
     * a retry then only has the failures left to do.
     */
    public function testTheOtherPartsAreStillRenderedWhenOneFails(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/broken.png', 'https://example.com/c.png']);
        $this->images->failFor('https://example.com/broken.png', new \RuntimeException('404'));

        $this->expectException(TaskProcessingFailed::class);

        try {
            $this->handle($task);
        } finally {
            $statuses = array_map(
                static fn (PartialVideo $p): string => $p->status()->value,
                $this->partials->listByTaskId($task->id()),
            );

            self::assertSame(['completed', 'failed', 'completed'], $statuses);
        }
    }

    /** A retry reuses what the previous attempt produced and redoes only the rest. */
    public function testARetryOnlyRedoesTheFailedParts(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/broken.png']);
        $this->images->failFor('https://example.com/broken.png', new \RuntimeException('404'));

        try {
            $this->handle($task);
        } catch (TaskProcessingFailed) {
            // first attempt, expected
        }

        $this->images = new FakeImageFetcher($this->dir->file('work'));
        $this->handle($task);

        self::assertSame(['https://example.com/broken.png'], $this->images->fetched);
        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
    }

    /**
     * A part whose file has gone missing - a volume recreated, a cleanup that
     * went too far - is rendered again rather than skipped, which would hand
     * the composer a path with nothing behind it.
     */
    public function testACompletedPartWhoseFileIsGoneIsRenderedAgain(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/broken.png']);
        $this->images->failFor('https://example.com/broken.png', new \RuntimeException('404'));

        try {
            $this->handle($task);
        } catch (TaskProcessingFailed) {
            // first attempt: one part succeeded, the other did not
        }

        $done = $this->partials->listByTaskId($task->id())[0];
        self::assertSame(PartialVideoStatus::COMPLETED, $done->status());
        unlink($this->dir->file('videos/partial_'.$done->id()->value.'.mp4'));

        $this->images = new FakeImageFetcher($this->dir->file('work'));
        $this->handle($task);

        self::assertSame(
            ['https://example.com/a.png', 'https://example.com/broken.png'],
            $this->images->fetched,
        );
        self::assertSame(VideoTaskStatus::COMPLETED, $task->status());
    }

    /**
     * ffmpeg's stderr is hundreds of lines of banner and stream detail; the
     * column that holds this is served to API clients.
     */
    public function testAnUnexpectedErrorIsNotEchoedBackToTheClient(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->images->failFor(
            'https://example.com/a.png',
            new \RuntimeException('SQLSTATE[HY000]: connection to 10.0.0.5 refused'),
        );

        try {
            $this->handle($task);
        } catch (TaskProcessingFailed) {
            // expected
        }

        $partial = $this->partials->listByTaskId($task->id())[0];

        self::assertSame('No se pudo generar el vídeo a partir de la imagen.', $partial->errorMessage());
    }

    /** A rejection the caller can act on is worth passing through, though. */
    public function testARefusedUrlIsReportedAsSuch(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->images->failFor('https://example.com/a.png', BlockedUrl::privateAddress('example.com', '10.0.0.1'));

        try {
            $this->handle($task);
        } catch (UnrecoverableMessageHandlingException) {
            // expected
        }

        self::assertSame(
            'El host "example.com" resuelve a una dirección no pública (10.0.0.1).',
            $this->partials->listByTaskId($task->id())[0]->errorMessage(),
        );
    }

    /**
     * And it is not retried. The policy refuses a URL for what it is - its
     * scheme, its port, the address its host resolves to - so every one of the
     * twenty attempts, spread over roughly three hours, held a worker to reach
     * the conclusion the first one had and the task ended `failed` with the
     * same answer it started with.
     */
    public function testATaskWhoseImagesAreAllRefusedIsNotRetried(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);
        $this->images->failFor('https://example.com/a.png', BlockedUrl::port(8080));
        $this->images->failFor('https://example.com/b.png', BlockedUrl::scheme('ftp'));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle($task);
    }

    /**
     * One failure that could pass later keeps the whole attempt retryable: the
     * parts that rendered are reused, so the retry only has the failures left
     * and the transient one is the reason to come back.
     */
    public function testOneTransientFailureAmongRefusalsIsStillRetried(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);
        $this->images->failFor('https://example.com/a.png', BlockedUrl::port(8080));
        $this->images->failFor('https://example.com/b.png', new \RuntimeException('el origen no respondió'));

        $this->expectException(TaskProcessingFailed::class);

        $this->handle($task);
    }

    public function testAFailedCompositionReleasesTheClaimAndLeavesTheTaskUnfinished(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->composer->failWith(new \RuntimeException('concat failed'));

        try {
            $this->handle($task);
            self::fail('a failed composition has to propagate');
        } catch (TaskProcessingFailed $e) {
            self::assertStringContainsString('componer el vídeo final', $e->getMessage());
        }

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
        self::assertNull($task->finalVideoUrl());
    }

    /**
     * Only a published file leaves staging, so what a failed render wrote there
     * stays: nothing reads that name again, nothing serves it, and the next
     * attempt writes a fresh one. On a busy deployment that is one truncated
     * video per failure, on the volume the finished ones live on.
     */
    public function testAFailedCompositionTakesItsHalfWrittenFileWithIt(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->composer->failWith(new \RuntimeException('concat failed'));

        try {
            $this->handle($task);
        } catch (TaskProcessingFailed) {
        }

        self::assertSame([], glob($this->dir->file('videos/.staging').'/*') ?: []);
    }

    public function testAFailedClipTakesItsHalfWrittenFileWithIt(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->animator->failWith(new \RuntimeException('ffmpeg died'));

        try {
            $this->handle($task);
        } catch (\Throwable) {
        }

        self::assertSame([], glob($this->dir->file('videos/.staging').'/*') ?: []);
    }

    /**
     * A misconfigured volume is a deployment problem, not a bad request: it is
     * reported, the claim goes back, and the retries get their chance.
     */
    public function testAnUnusableOutputDirectoryIsReportedAndTheClaimReleased(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);

        // A regular file where the videos directory should be: mkdir cannot
        // create it and nothing can be written into it.
        $blocked = $this->dir->file('blocked');
        file_put_contents($blocked, 'not a directory');

        try {
            $this->handlerWritingTo($blocked)(new ProcessVideoTaskCommand($task->id()->value));
            self::fail('an unusable videos directory has to propagate');
        } catch (TaskProcessingFailed $e) {
            self::assertStringContainsString('no es escribible', $e->getMessage());
        }

        self::assertSame(VideoTaskStatus::PENDING, $task->status());
    }

    /** The client that asked to be told is told, once, when the video exists. */
    public function testCompletionQueuesACallbackNotificationWhenTheTaskAskedForOne(): void
    {
        $task = VideoTask::create(['images' => ['https://example.com/a.png']], $this->clock->now(), 'https://client.example/hook');
        $this->tasks->save($task);
        $this->partials->saveAll([PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->clock->now())]);

        $this->handle($task);

        self::assertCount(1, $this->bus->dispatched);
        $message = $this->bus->dispatched[0];
        self::assertInstanceOf(NotifyTaskCallback::class, $message);
        self::assertSame($task->id()->value, $message->taskId);
        self::assertSame('completed', $message->event);
    }

    public function testATaskWithoutACallbackUrlQueuesNoNotification(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);

        $this->handle($task);

        self::assertSame([], $this->bus->dispatched);
    }

    /** A failed attempt is not an outcome yet: only the terminal status is notified. */
    public function testAFailedAttemptQueuesNoNotification(): void
    {
        $task = VideoTask::create(['images' => ['https://example.com/a.png']], $this->clock->now(), 'https://client.example/hook');
        $this->tasks->save($task);
        $this->partials->saveAll([PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->clock->now())]);
        $this->images->failFor('https://example.com/a.png', new \RuntimeException('connection reset'));

        try {
            $this->handle($task);
        } catch (TaskProcessingFailed) {
            // expected
        }

        self::assertSame([], $this->bus->dispatched);
    }

    /**
     * A cancellation lands while a part is being rendered. The worker notices
     * at the next boundary and stops: nothing more is fetched, no final video is
     * made, the message is acknowledged rather than retried, and the task stays
     * canceled - the cancellation is not undone by a "release".
     */
    public function testACancellationIsNoticedAtTheNextPartBoundary(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png', 'https://example.com/c.png']);
        $this->images->onFetch(function (string $url) use ($task): void {
            if ('https://example.com/a.png' === $url) {
                $this->tasks->cancel($task->id(), $this->clock->now());
            }
        });

        $this->handle($task);

        self::assertSame(['https://example.com/a.png'], $this->images->fetched);
        self::assertSame([], $this->composer->calls);
        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertNull($task->finalVideoUrl());

        // And the part that was in flight is not kept. It could only be kept by
        // publishing it under the name every run of this task shares, and by
        // then the attempt no longer holds the row: a retry claiming it between
        // the check and the rename would have this clip land where its own
        // belongs. A retry re-renders that one part instead.
        $statuses = array_map(
            static fn (PartialVideo $p): string => $p->status()->value,
            $this->partials->listByTaskId($task->id()),
        );
        self::assertSame(['pending', 'pending', 'pending'], $statuses);
    }

    /**
     * The very last gap: the cancellation arrives while ffmpeg is composing,
     * after the last boundary check has already passed. Writing "completed"
     * unconditionally at the end used to erase it, so the client was told the
     * task was canceled and then, a moment later, that it had completed.
     */
    public function testACancellationDuringTheCompositionIsNotOverwritten(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->composer->onCompose(function () use ($task): void {
            $this->tasks->cancel($task->id(), $this->clock->now());
        });

        $this->handle($task);

        self::assertSame(VideoTaskStatus::CANCELED, $this->tasks->currentStatus($task->id()));
        self::assertNull($this->tasks->get($task->id())?->finalVideoUrl());
        self::assertSame([], $this->bus->dispatched, 'and nobody is told the task completed');
    }

    /**
     * A run has no fixed length, and the lease used to be counted from the
     * claim: a task with enough parts outlived it while making perfectly good
     * progress, and a second worker took it over. Every boundary renews it -
     * and so does the moment each part and the composition finish, which is
     * where a renewal matters most: those are the long steps, and until then
     * nothing touched the row across one of them.
     */
    public function testTheClaimIsRenewedAsThePartsAreRendered(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);

        $this->handle($task);

        // Per part: one at the boundary before it, one when its clip is ready
        // to publish. Then one before composing and one when the video is.
        self::assertSame(6, $this->tasks->leaseRenewals);
    }

    /** Losing the claim to another worker stops the attempt as a cancellation does. */
    public function testAnAttemptWhoseClaimWasTakenOverStopsWithoutFinishing(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);
        $this->images->onFetch(function (string $url) use ($task): void {
            if ('https://example.com/a.png' === $url) {
                // What a takeover looks like from here: the row is no longer
                // this attempt's to write.
                $this->tasks->cancel($task->id(), $this->clock->now());
            }
        });

        $this->handle($task);

        self::assertSame(['https://example.com/a.png'], $this->images->fetched);
        self::assertSame([], $this->composer->calls);
    }

    /**
     * A replacement attempt owns the names this one was about to write.
     *
     * Cancel the task and retry it while the old worker is inside ffmpeg, and
     * the replacement puts the row back to `processing`. Publishing then lands
     * this attempt's clip where the replacement's belongs and marks its part
     * completed over work that is still running - so nothing is published, the
     * staged file goes, and the attempt stops at the next boundary as it would
     * for a cancellation.
     */
    public function testAnAttemptReplacedMidRenderPublishesNothing(): void
    {
        $task = $this->storedTask(['https://example.com/a.png', 'https://example.com/b.png']);
        $this->images->onFetch(function (string $url) use ($task): void {
            if ('https://example.com/a.png' !== $url) {
                return;
            }

            // Canceled, queued again and claimed by somebody else, all while
            // this attempt is rendering its first part. (The repository double
            // applies the cancellation to the aggregate itself, as the row and
            // the object are one thing in memory.)
            $this->tasks->cancel($task->id(), $this->clock->now());
            $task->retry($this->clock->now());
            $this->tasks->save($task);
            $this->tasks->claimForProcessing($task->id(), $this->clock->now(), $this->clock->now());
        });

        $this->handle($task);

        self::assertSame(['https://example.com/a.png'], $this->images->fetched);
        self::assertSame([], $this->composer->calls);
        self::assertSame(
            ['pending', 'pending'],
            array_map(
                static fn (PartialVideo $p): string => $p->status()->value,
                $this->partials->listByTaskId($task->id()),
            ),
            "the replacement's part is left as it found it",
        );
        self::assertSame(
            VideoTaskStatus::PROCESSING,
            $this->tasks->currentStatus($task->id()),
            'and the run that does hold the task is untouched',
        );
    }

    /**
     * Nor does a replaced attempt write its failures over the replacement's
     * work.
     *
     * Publication was fenced by the run and the failure was not, so the half of
     * the attempt that goes wrong was the half that could still reach the row.
     * A download that fails after the task has been taken over marked the
     * part failed on top of whatever the replacement had done with it - and if
     * the replacement went on to finish, the API answered with a completed task
     * carrying a part that reports an error and has no clip.
     */
    public function testAReplacedAttemptDoesNotRecordItsFailuresOverTheReplacement(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->images->failFor('https://example.com/a.png', new \RuntimeException('la descarga se cayó'));
        $this->images->onFetch(function () use ($task): void {
            // Taken over while this attempt was downloading; the failure below
            // is this attempt's and belongs to nobody else's row.
            $this->tasks->cancel($task->id(), $this->clock->now());
            $task->retry($this->clock->now());
            $this->tasks->save($task);
            $this->tasks->claimForProcessing($task->id(), $this->clock->now(), $this->clock->now());
        });

        try {
            $this->handle($task);
            self::fail('the attempt itself did fail');
        } catch (TaskProcessingFailed) {
            // Which is true of this attempt and of nothing else.
        }

        self::assertSame(
            ['pending'],
            array_map(
                static fn (PartialVideo $p): string => $p->status()->value,
                $this->partials->listByTaskId($task->id()),
            ),
            "the replacement's part is left as it found it",
        );
        self::assertSame(
            VideoTaskStatus::PROCESSING,
            $this->tasks->currentStatus($task->id()),
            'and the run that does hold the task is untouched',
        );
    }

    /** The composition is a boundary too: a task canceled after its last part is not finished. */
    public function testACancellationAfterTheLastPartStopsBeforeComposing(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $this->images->onFetch(function () use ($task): void {
            $this->tasks->cancel($task->id(), $this->clock->now());
        });

        $this->handle($task);

        self::assertSame([], $this->composer->calls);
        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
        self::assertFileDoesNotExist($this->dir->file('videos/final_'.$task->id()->value.'.mp4'));
    }

    public function testACanceledTaskIsNotClaimedByALateDelivery(): void
    {
        $task = $this->storedTask(['https://example.com/a.png']);
        $task->cancel($this->clock->now());

        $this->handle($task);

        self::assertSame([], $this->images->fetched);
        self::assertSame(VideoTaskStatus::CANCELED, $task->status());
    }

    /** @param list<string> $imageUrls */
    private function storedTask(array $imageUrls): VideoTask
    {
        $task = VideoTask::create(['images' => $imageUrls], $this->clock->now());
        $this->tasks->save($task);

        $partials = [];
        foreach ($imageUrls as $position => $url) {
            $partials[] = PartialVideo::create($task->id(), $url, Transition::PAN, $position, $this->clock->now());
        }

        $this->partials->saveAll($partials);

        return $task;
    }

    /**
     * A partial renders from a URL the client gave us, and for an object store
     * that is routinely a presigned one. Symfony's transport exceptions quote
     * the whole request URL back, so a connection that simply timed out wrote
     * the signature into the log line - where whoever operates the worker, and
     * anyone reading the failure transport, can pick it up.
     */
    public function testTheLogOfAFailedImageDoesNotCarryItsCredentials(): void
    {
        $signed = 'https://bucket.s3.amazonaws.com/img/a.png?X-Amz-Signature=deadbeef';
        $task = $this->storedTask([$signed]);
        $this->images->failFor($signed, new \RuntimeException(\sprintf('Connection timed out for "%s".', $signed)));
        $logger = new RecordingLogger();

        try {
            $this->handlerWritingTo($this->dir->file('videos'), $logger)(new ProcessVideoTaskCommand($task->id()->value));
        } catch (TaskProcessingFailed) {
            // The attempt failing is the point; what it wrote down is the test.
        }

        $logged = $logger->everythingLogged();
        self::assertStringNotContainsString('deadbeef', $logged);
        self::assertStringNotContainsString('X-Amz-Signature', $logged);
        self::assertStringNotContainsString('/img/a.png', $logged, 'nor the path, which is where a webhook keeps its token');
        self::assertStringContainsString('https://bucket.s3.amazonaws.com/…?…', $logged, 'the host it went to is still named');
        self::assertStringContainsString('Connection timed out', $logged, 'and so is what went wrong');
    }

    private function handle(VideoTask $task): void
    {
        $this->handler()(new ProcessVideoTaskCommand($task->id()->value));
    }

    private function handler(): ProcessVideoTaskHandler
    {
        return $this->handlerWritingTo($this->dir->file('videos'));
    }

    /** What the deployment renders with when a task chose nothing. */
    private static function defaults(): RenderOptions
    {
        return new RenderOptions(3.0, 30, '1280x720', 0.0);
    }

    private function handlerWritingTo(string $videosDir, ?LoggerInterface $logger = null): ProcessVideoTaskHandler
    {
        return new ProcessVideoTaskHandler(
            $this->tasks,
            $this->attempt,
            $this->partials,
            $this->clock,
            $this->images,
            $this->animator,
            $this->composer,
            new TaskCallbacks($this->bus, $this->tasks, $this->clock),
            self::defaults(),
            $videosDir,
            self::LEASE_SECONDS,
            self::ANIMATE_TIMEOUT_SECONDS,
            self::COMPOSE_TIMEOUT_SECONDS,
            $logger ?? new NullLogger(),
        );
    }
}
