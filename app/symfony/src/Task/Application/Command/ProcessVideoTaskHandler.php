<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\Exception\HasOperatorDetail;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
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
    public function __construct(
        private VideoTaskRepository $tasks,
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

        if (!$this->tasks->claimForProcessing($taskId, $now, $now->minusSeconds($this->leaseSeconds))) {
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
            $this->process($task);
        } catch (TaskCanceled) {
            // Not a failure, so nothing is rethrown and nothing is retried; the
            // cancellation already replaced the claim, so there is none to hand
            // back either.
            $this->logger->info('Task canceled while processing, attempt stopped', ['task_id' => $taskId->value]);
        } catch (\Throwable $e) {
            // Hand the claim back so the next delivery can take it. Without
            // this the task stays "processing" and every retry is refused by
            // the claim.
            $this->tasks->release($task->id(), $this->clock->now());

            throw $e;
        }
    }

    private function process(VideoTask $task): void
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

        foreach ($partials as $partial) {
            // Between parts, not during one: a running ffmpeg is left to finish
            // its clip, which a retry then reuses.
            $this->keepClaim($task);

            $reused = $this->reusableFile($partial);

            if (null !== $reused) {
                $clips[] = new Clip($reused, $partial->renderOptions($options)->duration);
                continue;
            }

            try {
                $clips[] = new Clip($this->renderPartial($task->id(), $partial, $options), $partial->renderOptions($options)->duration);
            } catch (\Throwable $e) {
                // One bad image must not cost the whole attempt: the rest of the
                // parts are rendered anyway, so a retry only has the failures
                // left to do and the response says which ones they were.
                ++$failed;
                $this->recordPartialFailure($partial, $e);
            }
        }

        if ($failed > 0) {
            throw TaskProcessingFailed::partialsFailed($failed, \count($partials));
        }

        $this->keepClaim($task);
        $this->composeFinal($task, $clips, $options);
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
    private function keepClaim(VideoTask $task): void
    {
        if ($this->tasks->renewLease($task->id(), $this->clock->now())) {
            return;
        }

        $status = $this->tasks->currentStatus($task->id());

        $this->logger->info('Claim lost, stopping the attempt', [
            'task_id' => $task->id()->value,
            'status' => $status?->value,
        ]);

        throw TaskCanceled::noticed($task->id()->value);
    }

    /** @param list<Clip> $clips */
    private function composeFinal(VideoTask $task, array $clips, RenderOptions $options): void
    {
        $name = 'final_'.$task->id()->value.'.mp4';
        $staged = $this->stagingDir().'/'.$name;

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

        self::publish($staged, $this->videosDir.'/'.$name);

        // The path, not a URL: the address a client is given is built when it
        // is asked for, so it follows the deployment and can carry a signature.
        $finalUrl = VideoUrls::PREFIX.$name;
        $completedAt = $this->clock->now();

        // Conditional, and that condition is the whole point: a cancellation
        // that arrived while ffmpeg was composing used to be overwritten here,
        // so a client was told the task was canceled and then that it had
        // completed. The video stays on disk either way; the retention job
        // clears it with the rest of the canceled task's files.
        if (!$this->tasks->complete($task->id(), $finalUrl, $completedAt)) {
            throw TaskCanceled::noticed($task->id()->value);
        }

        $task->markCompleted($finalUrl, $completedAt);
        $this->callbacks->notify($task);

        $this->logger->info('Task completed', [
            'task_id' => $task->id()->value,
            'parts' => \count($clips),
        ]);
    }

    /**
     * Renders one part and returns the file the composer must read.
     */
    private function renderPartial(UuidValue $taskId, PartialVideo $partial, RenderOptions $options): string
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
        $staged = $this->stagingDir().'/'.$name;
        $published = $this->videosDir.'/'.$name;

        $image = $this->images->fetch($partial->imageUrl(), $taskId->value.'/'.$partial->id()->value);

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

        self::publish($staged, $published);

        $partial->markCompleted(VideoUrls::PREFIX.$name, $this->clock->now());
        $this->partials->save($partial);

        return $published;
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
