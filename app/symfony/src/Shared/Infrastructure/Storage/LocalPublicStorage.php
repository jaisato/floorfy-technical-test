<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Domain\Storage\PublicStorage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class LocalPublicStorage implements PublicStorage
{
    public function __construct(
        private readonly string $projectDir,
        private readonly Filesystem $fs = new Filesystem(),
        private readonly string $publicPrefix = '/storage',
    ) {}

    public function put(string $relativePath, string $binary, string $mimeType): string
    {
        $relativePath = ltrim($relativePath, '/');
        $absDir = $this->projectDir.'/public/storage/'.\dirname($relativePath);
        $absPath = $this->projectDir.'/public/storage/'.$relativePath;

        $this->fs->mkdir($absDir);
        $this->fs->dumpFile($absPath, $binary);

        return $this->publicUrl($relativePath);
    }

    public function generatePath(string $prefix, string $extension): string
    {
        $prefix = trim($prefix, '/');
        $ext = ltrim($extension, '.');

        return $prefix.'/'.Uuid::v4()->toRfc4122().'.'.$ext;
    }

    public function publicUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        return rtrim($this->publicPrefix, '/').'/'.$relativePath;
    }
}
