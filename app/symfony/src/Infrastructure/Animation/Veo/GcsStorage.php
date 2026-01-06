<?php

declare(strict_types=1);

namespace App\Infrastructure\Animation\Veo;

use Google\Cloud\Storage\StorageClient;

final class GcsStorage
{
    public function __construct(
        private readonly StorageClient $storageClient,
    ) {
    }

    public function uploadFile(string $bucket, string $objectName, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        $b = $this->storageClient->bucket($bucket);
        $b->upload(
            fopen($localPath, 'rb'),
            [
                'name' => $objectName,
                'metadata' => [
                    'contentType' => $contentType,
                ],
            ]
        );
    }

    public function downloadTo(string $bucket, string $objectName, string $localPath): void
    {
        $b = $this->storageClient->bucket($bucket);
        $obj = $b->object($objectName);

        $dir = dirname($localPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio: '.$dir);
        }

        $obj->downloadToFile($localPath);
    }
}
