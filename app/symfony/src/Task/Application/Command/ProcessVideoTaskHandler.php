<?php

declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\Exception\HasOperatorDetail;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\Callback\TaskCallbacks;
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
        #[Autowire(param: 'app.videos_dir')]
        private string $videosDir,
        #[Autowire(param: 'app.public_base_url')]
        private string $publicBaseUrl,
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

        $files = [];
        $failed = 0;

        foreach ($partials as $partial) {
            // Between parts, not during one: a running ffmpeg is left to finish
            // its clip, which a retry then reuses.
            $this->stopIfCanceled($task);

            $reused = $this->reusableFile($partial);

            if (null !== $reused) {
                $files[] = $reused;
                continue;
            }

            try {
                $files[] = $this->renderPartial($task->id(), $partial);
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

        $this->stopIfCanceled($task);
        $this->composeFinal($task, $files);
    }

    /**
     * Reads the status the row has now: the copy in hand is from the start of
     * the attempt, and a cancellation is written by another process.
     */
    private function stopIfCanceled(VideoTask $task): void
    {
        if (VideoTaskStatus::CANCELED === $this->tasks->currentStatus($task->id())) {
            throw TaskCanceled::noticed($task->id()->value);
        }
    }

    /** @param list<string> $files */
    private function composeFinal(VideoTask $task, array $files): void
    {
        $name = 'final_'.$task->id()->value.'.mp4';
        $staged = $this->stagingDir().'/'.$name;

        try {
            $this->composer->compose($files, $staged);
        } catch (\Throwable $e) {
            $this->logFailure('Composition failed', $e, ['task_id' => $task->id()->value]);

            throw TaskProcessingFailed::compositionFailed();
        }

        self::publish($staged, $this->videosDir.'/'.$name);

        $task->markCompleted(rtrim($this->publicBaseUrl, '/').'/videos/'.$name, $this->clock->now());
        $this->tasks->save($task);
        $this->callbacks->notify($task);

        $this->logger->info('Task completed', [
            'task_id' => $task->id()->value,
            'parts' => \count($files),
        ]);
    }

    /**
     * Renders one part and returns the file the composer must read.
     */
    private function renderPartial(UuidValue $taskId, PartialVideo $partial): string
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
            $this->animator->animate($image, $partial->transition(), $staged);
        } finally {
            // The source image is of no use once the clip exists, and every
            // retry downloads a fresh copy anyway.
            @unlink($image);
        }

        self::publish($staged, $published);

        $partial->markCompleted('/videos/'.$name, $this->clock->now());
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

        $this->logger->error($what, $context + $detail + [
            'exception' => $e::class,
            'message' => $e->getMessage(),
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
