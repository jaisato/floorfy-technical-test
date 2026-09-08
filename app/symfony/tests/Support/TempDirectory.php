<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A scratch directory that removes itself, so a test never leaves anything in
 * the system temp directory and two runs never see each other's files.
 */
final readonly class TempDirectory
{
    public string $path;

    public function __construct(string $prefix = 'floorfy')
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));

        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new \RuntimeException('No se pudo crear el directorio temporal: '.$path);
        }

        $this->path = $path;
    }

    public function file(string $relative): string
    {
        return $this->path.'/'.ltrim($relative, '/');
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($this->path);
    }
}
