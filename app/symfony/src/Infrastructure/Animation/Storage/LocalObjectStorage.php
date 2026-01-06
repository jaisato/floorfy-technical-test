<?php
declare(strict_types=1);

namespace App\Infrastructure\Animation\Storage;

use App\Animation\Domain\Port\ObjectStorage;
use App\Infrastructure\Animation\Veo\GcsStorage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class LocalObjectStorage implements ObjectStorage
{
    public function __construct(
        private string $storageDir,
        private string $publicBaseUrl,
        private ?GcsStorage $gcs = null,
    ) {}

    public function importFromUrl(string $sourceUrl, string $targetPath): string
    {
        $fs = new Filesystem();
        $fs->mkdir($this->storageDir);

        $fullPath = rtrim($this->storageDir, '/').'/'.ltrim($targetPath, '/');
        $fs->mkdir(\dirname($fullPath));

        // file:// (tests / local dev)
        if (str_starts_with($sourceUrl, 'file://')) {
            $src = substr($sourceUrl, 7);
            $fs->copy($src, $fullPath, true);
            return $targetPath;
        }

        // gs://bucket/object
        if (str_starts_with($sourceUrl, 'gs://')) {
            if ($this->gcs === null) {
                throw new \RuntimeException('GcsStorage no configurado pero recepción de un gs://…');
            }
            $without = substr($sourceUrl, 5); // remove gs://
            [$bucket, $object] = explode('/', $without, 2);
            $this->gcs->downloadTo($bucket, $object, $fullPath);
            return $targetPath;
        }

        /**
         * Vertex/Veo devuelve normalmente un gcsUri (gs://...).
         * Si en algún punto lo transformas a URL HTTPS (storage.googleapis.com o storage.cloud.google.com),
         * NO intentes descargarlo con curl porque te dará 403 salvo que el objeto sea público.
         * Aquí lo normalizamos a bucket/object y lo descargamos vía API autenticada.
         */
        if ($this->gcs !== null) {
            $parsed = $this->tryParseGcsHttpUrl($sourceUrl);
            if ($parsed !== null) {
                [$bucket, $object] = $parsed;
                $this->gcs->downloadTo($bucket, $object, $fullPath);
                return $targetPath;
            }
        }

        // http(s)
        $process = new Process(['bash', '-lc', sprintf(
            'curl -L --fail %s -o %s',
            escapeshellarg($sourceUrl),
            escapeshellarg($fullPath)
        )]);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('No se pudo importar desde URL: '.$process->getErrorOutput());
        }

        return $targetPath;
    }

    /**
     * Intenta interpretar URLs de GCS a (bucket, object).
     * Soporta:
     *  - https://storage.googleapis.com/<bucket>/<object>
     *  - https://storage.cloud.google.com/<bucket>/<object>
     */
    private function tryParseGcsHttpUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? '';
        if ($host !== 'storage.googleapis.com' && $host !== 'storage.cloud.google.com') {
            return null;
        }

        $path = $parts['path'] ?? '';
        $path = ltrim($path, '/');
        if ($path === '') {
            return null;
        }

        // <bucket>/<object>
        $firstSlash = strpos($path, '/');
        if ($firstSlash === false) {
            return null;
        }

        $bucket = substr($path, 0, $firstSlash);
        $object = substr($path, $firstSlash + 1);
        if ($bucket === '' || $object === '') {
            return null;
        }

        // decode por si viene con %2F, etc.
        $object = rawurldecode($object);

        return [$bucket, $object];
    }

    public function publicUrl(string $path): string
    {
        return rtrim($this->publicBaseUrl, '/').'/'.ltrim($path, '/');
    }
}
