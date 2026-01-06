<?php

declare(strict_types=1);

namespace App\Animation\Infrastructure\Veo;

use App\Animation\Domain\Port\VeoClient;
use App\Shared\Domain\Storage\PublicStorage;
use Symfony\Component\Uid\Uuid;

final class FakeVeoClient implements VeoClient
{
    /**
     * Simulación en memoria: operationId -> [startedAt, videoUrl]
     * En producción real esto iría persistido (DB/cache), pero para el test vale.
     */
    private array $ops = [];

    public function __construct(private readonly PublicStorage $storage) {}

    public function startImageToVideo(string $prompt, string $imagePublicUrl): string
    {
        $op = 'fakeop_'.Uuid::v4()->toRfc4122();

        // Genera un mp4 “dummy” mínimo (no es un vídeo real). Si quieres, luego lo mejoramos con ffmpeg.
        $mp4Path = $this->storage->generatePath('videos', 'mp4');

        // Bytes mínimos para “algo” guardado. (Mejorable con ffmpeg; esto evita depender de binarios.)
        $dummy = "FAKE_MP4\nprompt=".$prompt."\nimage=".$imagePublicUrl."\n";
        $videoUrl = $this->storage->put($mp4Path, $dummy, 'video/mp4');

        $this->ops[$op] = [
            'startedAt' => time(),
            'videoUrl' => $videoUrl,
        ];

        return $op;
    }

    public function getOperation(string $operationId): array
    {
        if (!isset($this->ops[$operationId])) {
            return ['status' => 'FAILED', 'error' => 'Unknown operationId'];
        }

        // Simula “processing” 2 segundos
        $elapsed = time() - $this->ops[$operationId]['startedAt'];
        if ($elapsed < 2) {
            return ['status' => 'RUNNING'];
        }

        return ['status' => 'SUCCEEDED', 'videoUrl' => $this->ops[$operationId]['videoUrl']];
    }
}
