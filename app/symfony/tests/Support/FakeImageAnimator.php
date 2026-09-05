<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\ImageAnimator;

final class FakeImageAnimator implements ImageAnimator
{
    /** @var list<array{image: string, transition: string, output: string}> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    public function failWith(\Throwable $error): void
    {
        $this->failure = $error;
    }

    public function animate(string $imageFile, Transition $transition, string $outputFile): void
    {
        $this->calls[] = [
            'image' => $imageFile,
            'transition' => $transition->value,
            'output' => $outputFile,
        ];

        if (null !== $this->failure) {
            throw $this->failure;
        }

        file_put_contents($outputFile, 'clip for '.$transition->value);
    }
}
