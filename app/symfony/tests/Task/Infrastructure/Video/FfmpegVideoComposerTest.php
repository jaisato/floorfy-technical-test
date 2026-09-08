<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Video;

use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Task\Infrastructure\Video\FfmpegFailed;
use App\Task\Infrastructure\Video\FfmpegVideoComposer;
use App\Tests\Support\Argv;
use App\Tests\Support\RecordingProcessRunner;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class FfmpegVideoComposerTest extends TestCase
{
    private RecordingProcessRunner $runner;
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->runner = new RecordingProcessRunner();
        $this->dir = new TempDirectory('floorfy-composer');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testTheConcatListNamesEveryPartInOrder(): void
    {
        self::assertSame(
            "file '/videos/a.mp4'\nfile '/videos/b.mp4'\n",
            FfmpegVideoComposer::buildConcatList(self::clips('/videos/a.mp4', '/videos/b.mp4')),
        );
    }

    /**
     * The demuxer reads single-quoted paths, and a quote inside one is written
     * as '\'' - close, escape, reopen. Getting it wrong does not fail loudly:
     * it reads a different file.
     */
    public function testASingleQuoteInAPathIsEscapedForTheDemuxer(): void
    {
        self::assertSame(
            "file '/videos/it'\\''s here.mp4'\n",
            FfmpegVideoComposer::buildConcatList(self::clips("/videos/it's here.mp4")),
        );
    }

    public function testTheCommandCopiesTheStreamsAndAllowsAbsolutePaths(): void
    {
        $command = $this->composer()->buildConcatCommand('/work/list.txt', '/out/final.mp4');

        self::assertSame('ffmpeg', $command[0]);
        self::assertSame('concat', Argv::after($command, '-f'));
        // Without -safe 0 the demuxer refuses the absolute paths in the list.
        self::assertSame('0', Argv::after($command, '-safe'));
        self::assertSame('copy', Argv::after($command, '-c'));
        self::assertSame('/work/list.txt', Argv::after($command, '-i'));
        self::assertSame('/out/final.mp4', Argv::output($command));
    }

    public function testTheListIsWrittenWithTheRealPathsAndHandedToFfmpeg(): void
    {
        $seen = null;
        $this->runner->onRun(static function (array $command) use (&$seen): void {
            $seen = file_get_contents(Argv::after($command, '-i'));
            file_put_contents(Argv::output($command), 'final');
        });

        $this->composer()->compose(self::clips('/videos/a.mp4', '/videos/b.mp4'), $this->dir->file('final.mp4'), self::options());

        self::assertSame("file '/videos/a.mp4'\nfile '/videos/b.mp4'\n", $seen);
        self::assertFileExists($this->dir->file('final.mp4'));
    }

    /**
     * One list per attempt times twenty retries times every task adds up on a
     * volume nothing ever cleans.
     */
    public function testTheScratchListIsRemovedAfterASuccessfulRun(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        $this->composer()->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options());

        self::assertSame([], $this->concatLists());
    }

    public function testTheScratchListIsRemovedWhenTheRunFails(): void
    {
        $this->runner->willFail();

        try {
            $this->composer()->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options());
            self::fail('a failing ffmpeg run should be reported');
        } catch (FfmpegFailed) {
            // expected
        }

        self::assertSame([], $this->concatLists());
    }

    public function testFailureKeepsFfmpegOutputOutOfTheMessage(): void
    {
        $this->runner->willFail("ffmpeg version 7...\nInvalid data found when processing input\n");

        try {
            $this->composer()->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options());
            self::fail('a failing ffmpeg run should be reported');
        } catch (FfmpegFailed $e) {
            self::assertStringNotContainsString('Invalid data', $e->getMessage());
            self::assertStringContainsString('Invalid data', $e->operatorDetail()['stderr']);
        }
    }

    public function testAnExitCodeOfZeroWithNoFileIsStillAFailure(): void
    {
        $this->expectException(FfmpegFailed::class);
        $this->expectExceptionMessageMatches('/sin generar el fichero/');

        $this->composer()->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options());
    }

    public function testThereIsNothingToConcatenateWithoutParts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No hay vídeos parciales/');

        $this->composer()->compose([], $this->dir->file('final.mp4'), self::options());
    }

    public function testTheRunIsBudgeted(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        new FfmpegVideoComposer($this->runner, $this->dir->file('work'), 77)
            ->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options());

        self::assertSame(77, $this->runner->runs[0]['timeout']);
    }

    /**
     * The crossfade path re-encodes, so it is only taken when it was asked
     * for: a hard cut costs a file copy and must stay that way.
     */
    public function testWithoutACrossfadeTheCheapPathIsUsed(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        $this->composer()->compose(self::clips('/videos/a.mp4', '/videos/b.mp4'), $this->dir->file('final.mp4'), self::options());

        self::assertSame('concat', Argv::after($this->runner->lastCommand(), '-f'));
        self::assertNotContains('-filter_complex', $this->runner->lastCommand());
    }

    public function testACrossfadeChainsTheClipsThroughXfade(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        $this->composer()->compose(self::clips('/videos/a.mp4', '/videos/b.mp4'), $this->dir->file('final.mp4'), self::options(0.5));

        $command = $this->runner->lastCommand();

        self::assertSame(
            '[0:v][1:v]xfade=transition=fade:duration=0.5:offset=2.5[v1]',
            Argv::after($command, '-filter_complex'),
        );
        self::assertSame('[v1]', Argv::after($command, '-map'));
        self::assertSame('libx264', Argv::after($command, '-c:v'));
        self::assertSame('30', Argv::after($command, '-r'));
    }

    /** Every clip is an input of its own, in playback order. */
    public function testEveryClipIsAnInputOfTheCrossfadeCommand(): void
    {
        $command = $this->composer()->buildCrossfadeCommand(
            self::clips('/videos/a.mp4', '/videos/b.mp4', '/videos/c.mp4'),
            '/out/final.mp4',
            self::options(1.0),
        );

        self::assertSame(
            ['/videos/a.mp4', '/videos/b.mp4', '/videos/c.mp4'],
            Argv::all($command, '-i'),
        );
    }

    /**
     * offset(k) = the clips before it, minus the fades already consumed. With
     * three 3-second clips and a 1-second fade: 3-1 = 2, then 3+3-2 = 4.
     */
    public function testTheFadeOffsetsAccountForTheFadesAlreadyConsumed(): void
    {
        $command = $this->composer()->buildCrossfadeCommand(
            self::clips('/videos/a.mp4', '/videos/b.mp4', '/videos/c.mp4'),
            '/out/final.mp4',
            self::options(1.0),
        );

        self::assertSame(
            '[0:v][1:v]xfade=transition=fade:duration=1:offset=2[v1];[v1][2:v]xfade=transition=fade:duration=1:offset=4[v2]',
            Argv::after($command, '-filter_complex'),
        );
        self::assertSame('[v2]', Argv::after($command, '-map'));
    }

    /** A single clip has nothing to blend with, whatever was asked for. */
    public function testOneClipTakesTheCheapPathEvenWithACrossfade(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        $this->composer()->compose(self::clips('/videos/a.mp4'), $this->dir->file('final.mp4'), self::options(1.0));

        self::assertSame('concat', Argv::after($this->runner->lastCommand(), '-f'));
    }

    /**
     * A two-second fade between one-second clips is not a transition, it is a
     * command ffmpeg refuses.
     */
    public function testTheFadeIsCappedAtHalfTheShortestClip(): void
    {
        $clips = [new Clip('/videos/a.mp4', 3.0), new Clip('/videos/b.mp4', 1.0)];

        self::assertSame(0.5, FfmpegVideoComposer::fadeFor($clips, self::options(2.0)));
        self::assertSame(0.25, FfmpegVideoComposer::fadeFor($clips, self::options(0.25)));
    }

    /** @return list<Clip> */
    private static function clips(string ...$files): array
    {
        return array_values(array_map(static fn (string $file): Clip => new Clip($file, 3.0), $files));
    }

    private static function options(float $crossfade = 0.0): RenderOptions
    {
        return new RenderOptions(3.0, 30, '1280x720', $crossfade);
    }

    /** @return list<string> */
    private function concatLists(): array
    {
        return glob($this->dir->file('work').'/concat_*.txt') ?: [];
    }

    private function composer(): FfmpegVideoComposer
    {
        return new FfmpegVideoComposer($this->runner, $this->dir->file('work'));
    }
}
