<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Domain\Port\VideoComposer;

final class FakeVideoComposer implements VideoComposer
{
    /** @var list<list<string>> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    public function failWith(\Throwable $error): void
    {
        $this->failure = $error;
    }

    public function compose(array $localFiles, string $outputFile): void
    {
        $this->calls[] = $localFiles;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        file_put_contents($outputFile, 'final video of '.\count($localFiles).' parts');
    }
}
