<?php

declare(strict_types=1);

namespace App\Animation\Application\Command;

use App\Animation\Domain\Enum\AnimationStatus;
use App\Animation\Domain\Port\AnimationJobRepository;
use App\Animation\Domain\Port\ObjectStorage;
use App\Animation\Domain\Port\VeoAnimator;
use App\Shared\Domain\ValueObject\DateTimeValue;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class TrackAnimationStatusHandler
{
    public function __construct(
        private readonly VeoAnimator $animator,
        private readonly AnimationJobRepository $repository,
        private readonly ObjectStorage $storage,
    ) {}

    public function __invoke(TrackAnimationStatusCommand $command): void
    {
        $operationId = $command->operationId;

        $animationJob = $this->repository->getByOperationId($operationId);
        if ($animationJob === null) {
            // No tiene sentido reintentar si no existe el job asociado a esa operation.
            throw new UnrecoverableMessageHandlingException(sprintf(
                'No existe AnimationJob para operationId "%s".',
                $operationId
            ));
        }

        $result = $this->animator->pollAnimation($operationId);
        $now = DateTimeValue::now();
        // 1) Aún en curso -> reintentar más tarde (sin bus, usamos retry de Messenger).
        if ($result->status === AnimationStatus::RUNNING) {
            throw new RecoverableMessageHandlingException(sprintf(
                'Operación Veo aún en curso: %s',
                $operationId
            ));
        }

        // 2) Falló -> persistimos fallo y ACK (no reintentar).
        if ($result->status === AnimationStatus::FAILED) {
            $animationJob->markFailed($result->errorMessage ?? 'Veo failed (sin mensaje de error).', $now);
            $this->repository->save($animationJob);
            return;
        }

        // 3) SUCCEEDED pero puede venir incompleto (sin URL todavía) -> reintentar o fallar según haya error.
        if ($result->videoUrl === null) {
            // Si Veo nos dio un error explícito, marcamos failed.
            if ($result->errorMessage !== null && $result->errorMessage !== '') {
                $animationJob->markFailed($result->errorMessage, $now);
                $this->repository->save($animationJob);
                return;
            }

            // Si no hay error pero tampoco URL, normalmente es "resultado aún no disponible" o parseo incompleto.
            throw new RecoverableMessageHandlingException(sprintf(
                'Resultado Veo incompleto (sin videoUrl todavía) para operationId "%s".',
                $operationId
            ));
        }

        $targetPath = sprintf('animations/%s.mp4', $animationJob->id()->value);

        $storedPath = $this->storage->importFromUrl($result->videoUrl, $targetPath);
        $publicUrl  = $this->storage->publicUrl($storedPath);

        $animationJob->markSucceeded($publicUrl, $now);
        $this->repository->save($animationJob);
    }
}
