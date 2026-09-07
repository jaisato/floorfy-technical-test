<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\Exception\HasOperatorDetail;
use App\Shared\Domain\Exception\PermanentFailure;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Task\Domain\Exception\TaskCanceled;
use App\Task\Domain\Exception\TaskProcessingFailed;
use App\Task\Domain\Port\ImageAnimator;
use App\Task\Domain\Port\ImageFetcher;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoComposer;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Turns the images of a task into one video.
 *
 * Retry semantics, which is most of what this class is about:
 *
 * - The task is claimed with a conditional UPDATE, so a redelivered message or
 *   a second worker is told to drop it instead of reprocessing it.
 * - A failed attempt hands the claim back and rethrows, which is what makes the
 *   retries configured on the transport actually happen. Marking the task
 *   "failed" here - as an earlier revision did - settled it on the first error
 *   and made every one of those retries a no-op.
 * - "failed" is written once, when the retries are exhausted, by the listener
 *   on WorkerMessageFailedEvent.
 * - A message that can never succeed (an id that is not a UUID, a task with no
 *   images) is refused outright rather than retried twenty times.
 * - A cancellation that arrives mid-run is noticed at the next part boundary:
 *   the attempt stops, the message is acknowledged, and what was rendered so
 *   far stays on disk for a later retry to reuse.
 * - The claim is renewed at each of those boundaries. The lease used to be
 *   measured from the moment the task was claimed, so a long but perfectly
 *   healthy run - twenty images take as long as they take - outlived it and a
 *   second worker took the task off the first. Renewed, the deadline means "no
 *   progress since", which is what it was for.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class ProcessVideoTaskHandler
{
    /**
     * On top of the longest ffmpeg run, to cover what happens around it: the
     * rename that publishes the file, the conditional UPDATE that completes the
     * task, and the difference between two workers' clocks. See lease().
     */
    private const int LEASE_SLACK_SECONDS = 300;

    public function __construct(
        private VideoTaskRepository $tasks,
        private AttemptInFlight $attempt,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private ImageFetcher $images,
        private ImageAnimator $animator,
        private VideoComposer $composer,
        private TaskCallbacks $callbacks,
        private RenderOptions $defaultRenderOptions,
        #[Autowire(param: 'app.videos_dir')]
        private string $videosDir,
        #[Autowire(param: 'app.task_lease_seconds')]
        private int $leaseSeconds,
        #[Autowire(param: 'app.ffmpeg_animate_timeout')]
        private int $animateTimeoutSeconds,
        #[Autowire(param: 'app.ffmpeg_compose_timeout')]
        private int $composeTimeoutSeconds,
        #[Autowire(service: 'monolog.logger.task')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessVideoTaskCommand $command): void
    {
        $taskId = UuidValue::tryFromString($command->taskId);

        if (null === $taskId) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Identificador de tarea inválido: "%s".', $command->taskId));
        }

        $now = $this->clock->now();

        // Nothing of the previous delivery's is this one's.
        $this->attempt->none();

        // The number the claim produced is this attempt's, and every write it
        // makes from here carries it back. A status cannot do that job: cancel
        // this task and retry it while the attempt is inside ffmpeg and the row
        // says "processing" again, of somebody else's run.
        $generation = $this->tasks->claimForProcessing($taskId, $now, $now->minusSeconds($this->lease()));

        if (null === $generation) {
            // Already finished, or in the hands of another worker. Acknowledging
            // is right: retrying would not change the answer.
            $this->logger->info('Task not claimable, skipping', ['task_id' => $taskId->value]);

            return;
        }

        $task = $this->tasks->get($taskId);

        if (null === $task) {
            return;
        }

        $this->logger->info('Task processing started', ['task_id' => $taskId->value]);

        try {
            $this->process($task, $generation);
        } catch (TaskCanceled) {
            // Not a failure, so nothing is rethrown and nothing is retried; the
            // cancellation already replaced the claim, so there is none to hand
            // back either.
            $this->logger->info('Task canceled while processing, attempt stopped', ['task_id' => $taskId->value]);
        } catch (\Throwable $e) {
            // Hand the claim back so the next delivery can take it. Without
            // this the task stays "processing" and every retry is refused by
            // the claim.
            //
            // The generation it is handed back on is recorded, because the
            // listener that marks the task failed once the retries are spent
            // runs after this and cannot read it off the row: by then a
            // duplicate delivery may have claimed the task, and failing that
            // run marks a healthy attempt failed.
            $this->attempt->released($task->id(), $this->tasks->release($task->id(), $generation, $this->clock->now()));

            throw $e;
        }
    }

    private function process(VideoTask $task, int $generation): void
    {
        $partials = $this->partials->listByTaskId($task->id());

        if ([] === $partials) {
            $reason = TaskProcessingFailed::noPartials();

            throw new UnrecoverableMessageHandlingException($reason->getMessage(), previous: $reason);
        }

        $this->ensureWritable($this->videosDir);
        $this->ensureWritable($this->stagingDir());

        // A task written before options existed has none; the deployment's
        // defaults are what it was rendered with at the time.
        $options = $task->renderOptions() ?? $this->defaultRenderOptions;

        $clips = [];
        $failed = 0;
        $permanent = 0;

        foreach ($partials as $partial) {
            // Between parts, not during one: a running ffmpeg is left to finish
            // its clip, which a retry then reuses.
            $this->keepClaim($task->id(), $generation);

            $reused = $this->reusableFile($partial);

            if (null !== $reused) {
                $clips[] = new Clip($reused, $partial->renderOptions($options)->duration);
                continue;
            }

            try {
                $clips[] = new Clip($this->renderPartial($task->id(), $partial, $options, $generation), $partial->renderOptions($options)->duration);
            } catch (TaskCanceled $e) {
                // Not this part failing: the attempt itself is over, and the
                // parts after it are not this worker's to render.
                throw $e;
            } catch (\Throwable $e) {
                // One bad image must not cost the whole attempt: the rest of the
                // parts are rendered anyway, so a retry only has the failures
                // left to do and the response says which ones they were.
                ++$failed;
                $permanent += $e instanceof PermanentFailure ? 1 : 0;
                $this->recordPartialFailure($partial, $e);
            }
        }

        if ($failed > 0) {
            $reason = TaskProcessingFailed::partialsFailed($failed, \count($partials));

            // Nothing here is going to change: every image that failed was
            // refused by the URL policy, for what its URL is rather than for
            // anything that happened. Retried like a timeout, those twenty
            // attempts spread over roughly three hours each held a worker to
            // reach the conclusion the first one had, and the task reached
            // `failed` with exactly the same answer. One failure that *could*
            // pass later - a host that was down, a disk that was full - is
            // enough to keep the whole attempt retryable, because the parts
            // that already rendered are reused and only the failures are left.
            if ($permanent === $failed) {
                throw new UnrecoverableMessageHandlingException($reason->getMessage(), previous: $reason);
            }

            throw $reason;
        }

        $this->keepClaim($task->id(), $generation);
        $this->composeFinal($task, $clips, $options, $generation);
    }

    /**
     * One write that both proves this attempt still owns the task and pushes
     * the lease forward.
     *
     * It replaces a plain status read: reading told us about a cancellation but
     * said nothing about the lease, which kept expiring underneath a run that
     * was making perfectly good progress. The update touches the row only while
     * it is still "processing", so it fails for a cancellation and for a task
     * another worker has already taken over - and either way this attempt has
     * no business carrying on.
     */
    /**
     * How long a claim stands without a renewal before the task counts as
     * abandoned.
     *
     * The renewals happen between parts and never during one - a running ffmpeg
     * is left to finish its clip, which is what lets a retry reuse it - so the
     * longest a healthy attempt goes without touching the row is one ffmpeg
     * run, and the lease has to outlast that or the attempt declares itself
     * abandoned. Shipped, TASK_LEASE_SECONDS and FFMPEG_COMPOSE_TIMEOUT were
     * the same hour: a composition that used its whole budget expired the lease
     * exactly as it finished, and the next delivery of any message for that
     * task re-rendered everything on top of a worker that was still writing.
     *
     * So the configured value is a floor, not the answer: what the claim
     * actually gets is whichever is longer, and the operator's number decides
     * how quickly a genuinely dead worker's task comes back - which cannot be
     * sooner than the longest run they allow, because until then the two look
     * identical from here.
     */
    private function lease(): int
    {
        $longestStep = max($this->animateTimeoutSeconds, $this->composeTimeoutSeconds);

        return max($this->leaseSeconds, $longestStep + self::LEASE_SLACK_SECONDS);
    }

    private function keepClaim(UuidValue $taskId, int $generation): void
    {
        if ($this->tasks->renewLease($taskId, $generation, $this->clock->now())) {
            return;
        }

        $status = $this->tasks->currentStatus($taskId);

        $this->logger->info('Claim lost, stopping the attempt', [
            'task_id' => $taskId->value,
            'status' => $status?->value,
        ]);

        throw TaskCanceled::noticed($taskId->value);
    }

    /**
     * The prefix everything this attempt writes outside the published names
     * carries.
     *
     * A published file is addressed by the task or the part, and that name
     * cannot move: clients follow it and the reuse check looks for it. What is
     * on its way there can: two attempts of one task - the old one still
     * inside ffmpeg, the replacement started by a cancel and a retry - staged
     * into the same path and overwrote each other's work, and whichever
     * finished second published or unlinked a file it had not produced.
     */
    private static function attemptPrefix(int $generation): string
    {
        return 'g'.$generation.'_';
    }

    /** @param list<Clip> $clips */
    private function composeFinal(VideoTask $task, array $clips, RenderOptions $options, int $generation): void
    {
        $name = 'final_'.$task->id()->value.'.mp4';
        $staged = $this->stagingDir().'/'.self::attemptPrefix($generation).$name;

        try {
            $this->composer->compose($clips, $staged, $options);
        } catch (\Throwable $e) {
            // ffmpeg writes its output as it goes, so a run that failed part
            // way through leaves a truncated file under a name nothing will
            // ever publish or read again.
            @unlink($staged);
            $this->logFailure('Composition failed', $e, ['task_id' => $task->id()->value]);

            throw TaskProcessingFailed::compositionFailed();
        }

        // Asked now the composition is over, which is the longest thing this
        // attempt does: a cancellation plus a retry inside it hands the task to
        // a replacement, and publishing without asking puts this attempt's file
        // where the replacement's belongs. complete() below then refuses the
        // row, so the other run would be left serving a video it did not make.
        if ($this->mayPublish($task->id(), $generation, $staged)) {
            self::publish($staged, $this->videosDir.'/'.$name);
        }

        // The path, not a URL: the address a client is given is built when it
        // is asked for, so it follows the deployment and can carry a signature.
        $finalUrl = VideoUrls::PREFIX.$name;
        $completedAt = $this->clock->now();

        // Conditional, and that condition is the whole point: a cancellation
        // that arrived while ffmpeg was composing used to be overwritten here,
        // so a client was told the task was canceled and then that it had
        // completed. The video stays on disk either way; the retention job
        // clears it with the rest of the canceled task's files.
        $settled = $this->tasks->complete($task->id(), $finalUrl, $generation, $completedAt);

        if (null === $settled) {
            throw TaskCanceled::noticed($task->id()->value);
        }

        $task->markCompleted($finalUrl, $completedAt);
        $this->callbacks->notify($task, $settled);

        $this->logger->info('Task completed', [
            'task_id' => $task->id()->value,
            'parts' => \count($clips),
        ]);
    }

    /**
     * Renders one part and returns the file the composer must read.
     */
    private function renderPartial(UuidValue $taskId, PartialVideo $partial, RenderOptions $options, int $generation): string
    {
        $this->logger->info('Processing partial', [
            'task_id' => $taskId->value,
            'partial_id' => $partial->id()->value,
            'transition' => $partial->transition()->value,
        ]);

        if (!$partial->status()->isPending()) {
            // A part left over from an attempt that failed, or one whose file
            // has gone missing: start it again from scratch.
            $partial->markPending($this->clock->now());
        }

        $name = 'partial_'.$partial->id()->value.'.mp4';
        $staged = $this->stagingDir().'/'.self::attemptPrefix($generation).$name;
        $published = $this->videosDir.'/'.$name;

        $image = $this->images->fetch($partial->imageUrl(), $taskId->value.'/'.self::attemptPrefix($generation).$partial->id()->value);

        try {
            $this->animator->animate($image, $partial->transition(), $staged, $partial->renderOptions($options));
        } catch (\Throwable $e) {
            // Same as the composition step: whatever ffmpeg managed to write
            // before it gave up is a truncated file nothing will publish.
            @unlink($staged);

            throw $e;
        } finally {
            // The source image is of no use once the clip exists, and every
            // retry downloads a fresh copy anyway.
            @unlink($image);
        }

        // Asked before the clip leaves the staging area. Rendering takes as
        // long as ffmpeg takes, and a cancellation plus a retry in that window
        // hand the task to a replacement attempt: publishing then puts this
        // attempt's file where the replacement's belongs, and marks its part
        // completed over work that is still running.
        //
        // When it says no, the path returned is one nothing reads: the next
        // boundary check - or the one before the composition, for the last
        // part - finds the claim gone and stops the attempt before the clips
        // are used.
        if ($this->mayPublish($taskId, $generation, $staged)) {
            self::publish($staged, $published);

            $partial->markCompleted(VideoUrls::PREFIX.$name, $this->clock->now());
            $this->partials->save($partial);
        }

        return $published;
    }

    /**
     * Proves the claim before a staged file is published, and takes the file
     * away when it is gone.
     *
     * Left behind, that file is one nothing publishes and nothing reads: the
     * retention job enumerates the published names only, so it would sit on
     * the volume for as long as the deployment does.
     */
    private function mayPublish(UuidValue $taskId, int $generation, string $staged): bool
    {
        // Renewing is how ownership is asked: one write that answers whether
        // the row is still this attempt's and pushes the deadline out. It also
        // puts a renewal at the end of every long step, which is where the
        // lease most needs one - a part or a composition can take the better
        // part of an hour, and until now nothing touched the row in between.
        if ($this->tasks->renewLease($taskId, $generation, $this->clock->now())) {
            return true;
        }

        // Not this attempt's any more, and what to do depends on who has it. A
        // cancellation leaves these names nobody's: the clip is worth keeping,
        // because a retry reuses a part that is already rendered and the
        // retention job clears it with the rest of the task if none comes. A
        // *replacement attempt* is the other case - it owns these names now,
        // and publishing over it puts this attempt's output where the other's
        // belongs, or unlinks a file that is still being written.
        if (VideoTaskStatus::PROCESSING !== $this->tasks->currentStatus($taskId)) {
            return true;
        }

        // Nothing will ever publish or read the staged file now, and the
        // retention job enumerates the published names only, so it would sit
        // on the volume for as long as the deployment does.
        @unlink($staged);

        $this->logger->info('Another attempt holds the task; this one publishes nothing', [
            'task_id' => $taskId->value,
            'generation' => $generation,
        ]);

        return false;
    }

    /**
     * A part that is already done and whose file is still there, so a retry
     * does not redo work the previous attempt paid for.
     */
    private function reusableFile(PartialVideo $partial): ?string
    {
        if (!$partial->isCompleted()) {
            return null;
        }

        $file = $this->videosDir.'/partial_'.$partial->id()->value.'.mp4';

        return is_file($file) ? $file : null;
    }

    private function recordPartialFailure(PartialVideo $partial, \Throwable $e): void
    {
        $this->logFailure('Partial failed', $e, [
            'task_id' => $partial->taskId()->value,
            'partial_id' => $partial->id()->value,
        ]);

        $partial->markFailed(self::shortReason($e), $this->clock->now());
        $this->partials->save($partial);
    }

    /**
     * @param array<string, string> $context
     */
    private function logFailure(string $what, \Throwable $e, array $context): void
    {
        // ffmpeg writes hundreds of lines to stderr. They belong here, not in a
        // database column that is served to API clients.
        $detail = $e instanceof HasOperatorDetail ? $e->operatorDetail() : [];

        // A partial renders from a URL the client gave us, and for an object
        // store that is routinely a presigned one. Symfony's transport
        // exceptions quote the whole request URL back, so a connection that
        // simply timed out wrote the signature into this line.
        $this->logger->error($what, $context + $detail + [
            'exception' => $e::class,
            'message' => Urls::scrub($e->getMessage()),
        ]);
    }

    /**
     * Anything unexpected is reported generically: the message of an arbitrary
     * exception is not something to store on the task and hand to a client.
     */
    private static function shortReason(\Throwable $e): string
    {
        return $e instanceof ClientSafe ? $e->getMessage() : 'No se pudo generar el vídeo a partir de la imagen.';
    }

    /**
     * Moves a finished file into the directory nginx serves.
     *
     * Writing straight into the public directory publishes a half-written file:
     * a client polling the task can be handed a truncated video, and a crash
     * leaves one there for good. The staging directory is a sibling on the same
     * filesystem, so this is a rename, which is atomic.
     */
    private static function publish(string $staged, string $destination): void
    {
        if (!rename($staged, $destination)) {
            @unlink($staged);

            throw new \RuntimeException(\sprintf('No se pudo publicar el vídeo en "%s".', $destination));
        }
    }

    /**
     * Under the videos directory so that publishing is a rename rather than a
     * copy across filesystems; the leading dot keeps nginx from serving it.
     */
    private function stagingDir(): string
    {
        return $this->videosDir.'/.staging';
    }

    private function ensureWritable(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw TaskProcessingFailed::outputNotWritable($directory);
        }

        if (!is_writable($directory)) {
            throw TaskProcessingFailed::outputNotWritable($directory);
        }
    }
}
