<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Video;

use App\Task\Domain\Enum\Transition;
use App\Task\Infrastructure\Video\FfmpegFailed;
use App\Task\Infrastructure\Video\FfmpegImageAnimator;
use App\Tests\Support\Argv;
use App\Tests\Support\RecordingProcessRunner;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The filter graph is where the transitions actually live, so it is pinned as
 * an argv array. Running ffmpeg for real is a separate, opt-in test.
 */
final class FfmpegImageAnimatorTest extends TestCase
{
    private RecordingProcessRunner $runner;
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->runner = new RecordingProcessRunner();
        $this->dir = new TempDirectory('floorfy-animator');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    /** @return iterable<string, array{Transition, string}> */
    public static function transitions(): iterable
    {
        yield 'pan' => [Transition::PAN, "zoompan=z='1.1':x='max(0,min(iw-(iw/zoom),(iw-(iw/zoom))*on/90))'"];
        yield 'zoom in' => [Transition::ZOOM_IN, "zoompan=z='min(1.0+0.002*on,1.2)'"];
        yield 'zoom out' => [Transition::ZOOM_OUT, "zoompan=z='max(1.2-0.002*on,1.0)'"];
    }

    #[DataProvider('transitions')]
    public function testEachTransitionHasItsOwnCameraMove(Transition $transition, string $expectedFilter): void
    {
        $command = $this->animator()->buildCommand('/in/image.png', $transition, '/out/clip.mp4');

        self::assertStringContainsString($expectedFilter, self::filterGraph($command));
    }

    public function testTheImageIsScaledAndCroppedToCoverTheFrameBeforeTheMove(): void
    {
        $graph = self::filterGraph($this->animator()->buildCommand('/in/image.png', Transition::PAN, '/out/clip.mp4'));

        self::assertStringStartsWith(
            'scale=1280:720:force_original_aspect_ratio=increase,crop=1280:720,',
            $graph,
        );
    }

    public function testTheCommandIsAnArgvArrayWithTheInputAndOutputInPlace(): void
    {
        $command = $this->animator()->buildCommand('/in/my image.png', Transition::PAN, '/out/my clip.mp4');

        self::assertSame('ffmpeg', $command[0]);
        self::assertSame('/in/my image.png', Argv::after($command, '-i'));
        self::assertSame('/out/my clip.mp4', Argv::output($command));
    }

    /**
     * A worker that blocks on a prompt is a worker that never finishes, and
     * ffmpeg's default log level buries anything useful.
     */
    public function testTheCommandNeverWaitsForInputAndKeepsItsOutputTerse(): void
    {
        $command = $this->animator()->buildCommand('/in/image.png', Transition::PAN, '/out/clip.mp4');

        self::assertContains('-nostdin', $command);
        self::assertContains('-y', $command);
        self::assertSame('error', Argv::after($command, '-loglevel'));
    }

    public function testTheThreadCapIsPassedThrough(): void
    {
        $command = new FfmpegImageAnimator($this->runner, 600, 2)
            ->buildCommand('/in/image.png', Transition::PAN, '/out/clip.mp4');

        self::assertSame('2', Argv::after($command, '-threads'));
    }

    /** A locale that writes 3,0 for three would produce a command ffmpeg cannot read. */
    public function testTheDurationIsFormattedIndependentlyOfTheLocale(): void
    {
        $previous = setlocale(\LC_NUMERIC, '0');
        setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'C');

        try {
            $command = $this->animator()->buildCommand('/in/image.png', Transition::PAN, '/out/clip.mp4');

            self::assertSame('3', Argv::after($command, '-t'));
        } finally {
            setlocale(\LC_NUMERIC, false === $previous ? 'C' : $previous);
        }
    }

    public function testTheOutputDirectoryIsCreatedAndTheRunIsBudgeted(): void
    {
        $output = $this->dir->file('nested/deeper/clip.mp4');
        $this->runner->onRun(static function (array $command): void {
            file_put_contents(Argv::output($command), 'clip');
        });

        new FfmpegImageAnimator($this->runner, 42)->animate('/in/image.png', Transition::PAN, $output);

        self::assertFileExists($output);
        self::assertSame(42, $this->runner->runs[0]['timeout']);
    }

    public function testAFailedRunReportsTheExitCodeAndKeepsStderrOutOfTheMessage(): void
    {
        $this->runner->willFail("ffmpeg version 7...\n[libx264 @ 0x1] no such file\n");

        try {
            $this->animator()->animate('/in/image.png', Transition::PAN, $this->dir->file('clip.mp4'));
            self::fail('a failing ffmpeg run should be reported');
        } catch (FfmpegFailed $e) {
            self::assertStringNotContainsString('libx264', $e->getMessage());
            self::assertStringContainsString('libx264', $e->operatorDetail()['stderr']);
            self::assertStringContainsString('ffmpeg', $e->operatorDetail()['command']);
        }
    }

    /** ffmpeg can exit 0 having written nothing; that is not a clip. */
    public function testAnExitCodeOfZeroWithNoFileIsStillAFailure(): void
    {
        $this->expectException(FfmpegFailed::class);
        $this->expectExceptionMessageMatches('/sin generar el fichero/');

        $this->animator()->animate('/in/image.png', Transition::PAN, $this->dir->file('clip.mp4'));
    }

    /** @param list<string> $command */
    private static function filterGraph(array $command): string
    {
        return Argv::after($command, '-vf');
    }

    private function animator(): FfmpegImageAnimator
    {
        return new FfmpegImageAnimator($this->runner);
    }
}
