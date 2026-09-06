<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Domain\Port\VideoComposer;
use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;

final class FakeVideoComposer implements VideoComposer
{
    /** @var list<list<Clip>> */
    public array $calls = [];

    /** @var list<RenderOptions> */
    public array $options = [];

    private ?\Throwable $failure = null;

    /** @var (callable(): void)|null runs while the composition is "in progress" */
    private $during;

    public function failWith(\Throwable $error): void
    {
        $this->failure = $error;
    }

    /**
     * Something that happens while ffmpeg would be running: a cancellation
     * landing after the last boundary check, for instance.
     *
     * @param callable(): void $callback
     */
    public function onCompose(callable $callback): void
    {
        $this->during = $callback;
    }

    public function compose(array $clips, string $outputFile, RenderOptions $options): void
    {
        $this->calls[] = $clips;
        $this->options[] = $options;

        if (null !== $this->during) {
            ($this->during)();
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        file_put_contents($outputFile, 'final video of '.\count($clips).' parts');
    }

    /**
     * The files of the last composition, which is what most tests care about.
     *
     * @return list<string>
     */
    public function lastFiles(): array
    {
        $last = $this->calls[array_key_last($this->calls) ?? 0] ?? [];

        return array_map(static fn (Clip $clip): string => $clip->file, $last);
    }
}
