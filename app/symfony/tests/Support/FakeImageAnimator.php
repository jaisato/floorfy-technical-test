<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Domain\Enum\Transition;
use App\Task\Domain\Port\ImageAnimator;
use App\Task\Domain\ValueObject\RenderOptions;

final class FakeImageAnimator implements ImageAnimator
{
    /** @var list<array{image: string, transition: string, output: string, options: RenderOptions}> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    /**
     * ffmpeg writes its output as it encodes, so a run that gives up part way
     * has already put a truncated file where the finished one was going. The
     * fake does the same: a failure that left nothing behind would never show
     * whether the handler cleans up after one that did.
     */
    public function failWith(\Throwable $error): void
    {
        $this->failure = $error;
    }

    public function animate(string $imageFile, Transition $transition, string $outputFile, RenderOptions $options): void
    {
        $this->calls[] = [
            'image' => $imageFile,
            'transition' => $transition->value,
            'output' => $outputFile,
            'options' => $options,
        ];

        if (null !== $this->failure) {
            file_put_contents($outputFile, 'half a clip');

            throw $this->failure;
        }

        file_put_contents($outputFile, 'clip for '.$transition->value);
    }
}
