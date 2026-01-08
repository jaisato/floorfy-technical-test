<?php
declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Composition\Domain\Port\VideoComposer;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Enum\PartialVideoStatus;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Infrastructure\Media\ImageDownloader;
use App\Task\Infrastructure\Video\FfmpegImageAnimator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\HttpKernel\KernelInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final class ProcessVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private Clock $clock,
        private ImageDownloader $imageDownloader,
        private FfmpegImageAnimator $animator,
        private VideoComposer $composer,
        private KernelInterface $kernel,
        private string $appUrl,
        #[Autowire(service: 'monolog.logger.task')]
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessVideoTaskCommand $cmd): void
    {
        $this->logger->info('Task processing started', [
            'task_id' => $cmd->taskId,
        ]);

        $now = $this->clock->now();
        $taskId = UuidValue::fromString($cmd->taskId);

        $task = $this->tasks->get($taskId);
        if ($task === null) {
            return;
        }

        if (in_array($task->status()->value, ['completed', 'failed'], true)) {
            return;
        }

        $task->markProcessing($now);
        $this->tasks->save($task);

        $partials = $this->partials->listByTaskId($taskId);

        $this->logger->debug('Loaded partials', [
            'task_id' => $taskId->value,
            'count' => count($partials),
        ]);

        $fs = new Filesystem();

        $publicDir = rtrim($this->kernel->getProjectDir(), '/').'/public';
        $videosDir = $publicDir.'/videos';
        $fs->mkdir($videosDir, 0775);

        if (!is_dir($videosDir) || !is_writable($videosDir)) {
            throw new \RuntimeException(sprintf(
                'Directorio no escribible: %s (uid=%s gid=%s)',
                $videosDir,
                (string) getmyuid(),
                (string) getmygid(),
            ));
        }

        $localPartFiles = [];

        try {
            foreach ($partials as $partial) {
                $this->logger->info('Processing partial', [
                    'task_id' => $taskId->value,
                    'partial_id' => $partial->id()->value,
                    'image_url' => $partial->imageUrl(),
                    'transition' => $partial->transition()->value,
                ]);

                if ($partial->status() !== PartialVideoStatus::PENDING) {
                    if ($partial->videoPath()) {
                        $local = $publicDir.$partial->videoPath();
                        if (is_file($local)) {
                            $localPartFiles[] = 'file://'.$local;
                        }
                    }
                    continue;
                }

                try {
                    $img = $this->imageDownloader->download(
                        $partial->imageUrl(),
                        $taskId->value.'/'.($partial->id()->value).'.img'
                    );

                    $partialRelPath = '/videos/partial_'.$partial->id()->value.'.mp4';
                    $partialAbsPath = $publicDir.$partialRelPath;

                    $this->animator->animate($img, $partial->transition(), $partialAbsPath);

                    $partial->markCompleted($partialRelPath, $this->clock->now());
                    $this->partials->save($partial);

                    $localPartFiles[] = 'file://'.$partialAbsPath;
                } catch (\Throwable $e) {
                    $partial->markFailed($e->getMessage(), $this->clock->now());
                    $this->partials->save($partial);
                    throw $e;
                }
            }

            if ($localPartFiles === []) {
                $partials = $this->partials->listByTaskId($taskId);
                foreach ($partials as $partial) {
                    if ($partial->videoPath() && is_file($publicDir.$partial->videoPath())) {
                        $localPartFiles[] = 'file://'.$publicDir.$partial->videoPath();
                    }
                }
            }

            if (count($localPartFiles) !== count($partials)) {
                throw new \RuntimeException('No se han generado todos los partial videos.');
            }

            $outTmp = $this->composer->compose($localPartFiles, 'task_'.$taskId->value);

            $finalRelPath = '/videos/final_'.$taskId->value.'.mp4';
            $finalAbsPath = $publicDir.$finalRelPath;
            $fs->copy($outTmp, $finalAbsPath, true);

            $finalUrl = rtrim($this->appUrl, '/').$finalRelPath;

            $task->markCompleted($finalUrl, $this->clock->now());
            $this->tasks->save($task);
        } catch (\Throwable $e) {
            $this->logger->error('Task failed', [
                'task_id' => $taskId->value,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $task->markFailed($e->getMessage(), $this->clock->now());
            $this->tasks->save($task);

            throw $e;
        }
    }
}
