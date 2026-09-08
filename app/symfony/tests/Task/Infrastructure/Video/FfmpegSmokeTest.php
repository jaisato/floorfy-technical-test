<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Video;

use App\Shared\Infrastructure\Process\SymfonyProcessRunner;
use App\Task\Domain\Enum\Transition;
use App\Task\Domain\ValueObject\Clip;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Task\Infrastructure\Video\FfmpegImageAnimator;
use App\Task\Infrastructure\Video\FfmpegVideoComposer;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The one test that needs ffmpeg on PATH, so it is excluded by default and run
 * in CI. Everything else about these adapters is pinned as an argv array.
 *
 * What it proves is the part no argv assertion can: that the filter graphs are
 * ones ffmpeg actually accepts, and that the concat demuxer reads the list this
 * application writes.
 */
#[Group('ffmpeg')]
final class FfmpegSmokeTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory('floorfy-ffmpeg');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    /** @return iterable<string, array{Transition}> */
    public static function transitions(): iterable
    {
        yield 'pan' => [Transition::PAN];
        yield 'zoom in' => [Transition::ZOOM_IN];
        yield 'zoom out' => [Transition::ZOOM_OUT];
    }

    #[DataProvider('transitions')]
    public function testEveryTransitionProducesAPlayableClip(Transition $transition): void
    {
        $clip = $this->dir->file('clip.mp4');

        $this->animator()->animate($this->image('source.png'), $transition, $clip, self::options());

        self::assertFileExists($clip);
        self::assertGreaterThan(1024, (int) filesize($clip));
    }

    public function testTheClipsAreConcatenatedIntoOneVideo(): void
    {
        $first = $this->dir->file('a.mp4');
        $second = $this->dir->file('b.mp4');
        $final = $this->dir->file('final.mp4');

        $this->animator()->animate($this->image('a.png'), Transition::PAN, $first, self::options());
        $this->animator()->animate($this->image('b.png'), Transition::ZOOM_IN, $second, self::options());

        new FfmpegVideoComposer(new SymfonyProcessRunner(), $this->dir->file('work'))
            ->compose([new Clip($first, 1.0), new Clip($second, 1.0)], $final, self::options());

        self::assertFileExists($final);
        self::assertGreaterThan(filesize($first), filesize($final));
    }

    private function animator(): FfmpegImageAnimator
    {
        return new FfmpegImageAnimator(new SymfonyProcessRunner(), 120, 1);
    }

    /**
     * The shortest clip the options allow, at the smallest frame size, keeps
     * the suite quick; the filter graph is the one production uses.
     */
    private static function options(float $crossfade = 0.0): RenderOptions
    {
        return new RenderOptions(1.0, 24, '1280x720', $crossfade);
    }

    private function image(string $name): string
    {
        $path = $this->dir->file($name);

        $image = imagecreatetruecolor(640, 480);
        self::assertNotFalse($image);

        $colour = imagecolorallocate($image, 20, 120, 200);
        self::assertNotFalse($colour);
        imagefilledrectangle($image, 0, 0, 640, 480, $colour);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
