<?php

declare(strict_types=1);

namespace App\Animation\Domain\Port;

interface VeoClient
{
    /**
     * Inicia generación asíncrona y devuelve un operationId.
     */
    public function startImageToVideo(string $prompt, string $imagePublicUrl): string;

    /**
     * Devuelve estado y, si está listo, la URL pública del vídeo.
     *
     * @return array{status: 'PENDING'|'RUNNING'|'SUCCEEDED'|'FAILED', videoUrl?: string, error?: string}
     */
    public function getOperation(string $operationId): array;
}
