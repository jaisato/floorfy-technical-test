<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Gcp;

use Google\Cloud\Storage\StorageClient;

final class GcsDownloader
{
    public function __construct(
        private readonly StorageClient $storage,
        private readonly string $publicDir,      // %kernel.project_dir%/public
        private readonly string $publicBaseUrl,  // ej. http://localhost:8080
    ) {}

    /**
     * Descarga gs://bucket/path/file.mp4 a public/storage/<jobId>.mp4
     * y devuelve URL pública (BASE_PUBLIC_URL + /storage/<jobId>.mp4)
     */
    public function downloadToPublicStorage(string $gcsUri, string $jobId): string
    {
        [$bucket, $object] = $this->parseGcsUri($gcsUri);

        $targetDir = rtrim($this->publicDir, '/').'/storage';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear public/storage');
        }

        $targetPath = $targetDir.'/'.$jobId.'.mp4';

        $bucketObj = $this->storage->bucket($bucket);
        $objectObj = $bucketObj->object($object);

        if (!$objectObj->exists()) {
            throw new \RuntimeException(sprintf('El objeto no existe en GCS: %s', $gcsUri));
        }

        $objectObj->downloadToFile($targetPath);

        return rtrim($this->publicBaseUrl, '/').'/storage/'.$jobId.'.mp4';
    }

    private function parseGcsUri(string $gcsUri): array
    {
        if (!str_starts_with($gcsUri, 'gs://')) {
            throw new \InvalidArgumentException('URI GCS inválida: '.$gcsUri);
        }

        $noScheme = substr($gcsUri, 5);
        $parts = explode('/', $noScheme, 2);

        $bucket = $parts[0] ?? '';
        $object = $parts[1] ?? '';

        if ($bucket === '' || $object === '') {
            throw new \InvalidArgumentException('URI GCS inválida: '.$gcsUri);
        }

        return [$bucket, $object];
    }
}
