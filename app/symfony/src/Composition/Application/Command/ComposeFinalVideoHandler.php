<?php
declare(strict_types=1);

namespace App\Composition\Application\Command;

use App\Animation\Domain\Port\ObjectStorage;
use App\Composition\Domain\Port\CompositionJobRepository;
use App\Composition\Domain\Port\VideoComposer;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\UuidValue;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final class ComposeFinalVideoHandler
{
    public function __construct(
        private CompositionJobRepository $repo,
        private VideoComposer $composer,
        private ObjectStorage $storage,
        private Clock $clock,
    ) {}

    public function __invoke(ComposeFinalVideoCommand $cmd): void
    {
        $job = $this->repo->get(UuidValue::fromString($cmd->compositionId));
        if (!$job) return;

        $now = $this->clock->now();
        $job->markRunning($now->toDateTimeImmutable());
        $this->repo->save($job);

        try {
            $localOutput = $this->composer->compose($job->animationVideoUrls(), $job->id()->value);

            $targetPath = sprintf('compositions/%s.mp4', $job->id()->value);
            $storedPath = $this->storage->importFromUrl('file://' . $localOutput, $targetPath);
            $publicUrl  = $this->storage->publicUrl($storedPath);

            $job->markSucceeded($publicUrl, $this->clock->now()->toDateTimeImmutable());
            $this->repo->save($job);
        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage(), $this->clock->now()->toDateTimeImmutable());
            $this->repo->save($job);
            throw $e;
        }
    }
}
