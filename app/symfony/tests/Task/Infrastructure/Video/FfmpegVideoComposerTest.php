<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Video;

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
            FfmpegVideoComposer::buildConcatList(['/videos/a.mp4', '/videos/b.mp4']),
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
            FfmpegVideoComposer::buildConcatList(["/videos/it's here.mp4"]),
        );
    }

    public function testTheCommandCopiesTheStreamsAndAllowsAbsolutePaths(): void
    {
        $command = $this->composer()->buildCommand('/work/list.txt', '/out/final.mp4');

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

        $this->composer()->compose(['/videos/a.mp4', '/videos/b.mp4'], $this->dir->file('final.mp4'));

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

        $this->composer()->compose(['/videos/a.mp4'], $this->dir->file('final.mp4'));

        self::assertSame([], $this->concatLists());
    }

    public function testTheScratchListIsRemovedWhenTheRunFails(): void
    {
        $this->runner->willFail();

        try {
            $this->composer()->compose(['/videos/a.mp4'], $this->dir->file('final.mp4'));
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
            $this->composer()->compose(['/videos/a.mp4'], $this->dir->file('final.mp4'));
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

        $this->composer()->compose(['/videos/a.mp4'], $this->dir->file('final.mp4'));
    }

    public function testThereIsNothingToConcatenateWithoutParts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No hay vídeos parciales/');

        $this->composer()->compose([], $this->dir->file('final.mp4'));
    }

    public function testTheRunIsBudgeted(): void
    {
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'final');
        });

        new FfmpegVideoComposer($this->runner, $this->dir->file('work'), 77)
            ->compose(['/videos/a.mp4'], $this->dir->file('final.mp4'));

        self::assertSame(77, $this->runner->runs[0]['timeout']);
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
