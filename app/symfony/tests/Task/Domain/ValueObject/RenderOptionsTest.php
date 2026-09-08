<?php

declare(strict_types=1);

namespace App\Tests\Task\Domain\ValueObject;

use App\Task\Domain\Exception\InvalidRenderOptions;
use App\Task\Domain\ValueObject\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenderOptionsTest extends TestCase
{
    public function testTheResolutionIsReadAsAWidthAndAHeight(): void
    {
        $options = new RenderOptions(3.0, 30, '1920x1080', 0.0);

        self::assertSame(1920, $options->width());
        self::assertSame(1080, $options->height());
    }

    public function testZeroCrossfadeIsAHardCut(): void
    {
        self::assertFalse(new RenderOptions(3.0, 30, '1280x720', 0.0)->hasCrossfade());
        self::assertTrue(new RenderOptions(3.0, 30, '1280x720', 0.5)->hasCrossfade());
    }

    /**
     * @return iterable<string, array{float, int, string, float}>
     */
    public static function impossibleOptions(): iterable
    {
        yield 'too short' => [0.9, 30, '1280x720', 0.0];
        yield 'too long' => [15.1, 30, '1280x720', 0.0];
        yield 'unknown frame rate' => [3.0, 60, '1280x720', 0.0];
        yield 'unknown resolution' => [3.0, 30, '640x480', 0.0];
        yield 'negative crossfade' => [3.0, 30, '1280x720', -0.1];
        yield 'crossfade too long' => [3.0, 30, '1280x720', 2.1];
    }

    /** The backstop: the API refuses these long before the domain sees them. */
    #[DataProvider('impossibleOptions')]
    public function testOptionsThatCannotProduceAVideoAreRefused(float $duration, int $fps, string $resolution, float $crossfade): void
    {
        $this->expectException(InvalidRenderOptions::class);

        new RenderOptions($duration, $fps, $resolution, $crossfade);
    }

    public function testTheBoundsThemselvesAreAllowed(): void
    {
        $shortest = new RenderOptions(RenderOptions::MIN_DURATION, 24, '1280x720', 0.0);
        $longest = new RenderOptions(RenderOptions::MAX_DURATION, 30, '1920x1080', RenderOptions::MAX_CROSSFADE);

        self::assertSame(RenderOptions::MIN_DURATION, $shortest->duration);
        self::assertSame(RenderOptions::MAX_CROSSFADE, $longest->crossfade);
    }

    /** An option a request did not send keeps the deployment's default. */
    public function testOverridesReplaceOnlyWhatTheyName(): void
    {
        $defaults = new RenderOptions(3.0, 30, '1280x720', 0.0);

        $chosen = $defaults->with(['fps' => 24, 'crossfade' => 0.5]);

        self::assertSame(3.0, $chosen->duration);
        self::assertSame(24, $chosen->fps);
        self::assertSame('1280x720', $chosen->resolution);
        self::assertSame(0.5, $chosen->crossfade);
    }

    public function testNoOverridesLeavesTheDefaultsAlone(): void
    {
        $defaults = new RenderOptions(3.0, 30, '1280x720', 0.0);

        self::assertEquals($defaults, $defaults->with([]));
    }

    /** JSON sends 3 for three seconds as readily as 3.0. */
    public function testAnIntegerIsAcceptedWhereSecondsAreExpected(): void
    {
        self::assertSame(5.0, new RenderOptions(3.0, 30, '1280x720', 0.0)->with(['duration' => 5])->duration);
    }

    public function testOnlyTheDurationChangesPerImage(): void
    {
        $task = new RenderOptions(3.0, 24, '1920x1080', 0.5);

        $clip = $task->withDuration(8.0);

        self::assertSame(8.0, $clip->duration);
        self::assertSame(24, $clip->fps);
        self::assertSame('1920x1080', $clip->resolution);
        self::assertSame(0.5, $clip->crossfade);
    }

    public function testItSurvivesARoundTripThroughStorage(): void
    {
        $options = new RenderOptions(4.5, 25, '1920x1080', 1.25);

        self::assertEquals($options, RenderOptions::fromArray($options->toArray()));
    }

    /** The stored shape is what the JSON column holds; it is part of the contract. */
    public function testTheStoredShapeIsNamedFields(): void
    {
        self::assertSame(
            ['duration' => 4.5, 'fps' => 25, 'resolution' => '1920x1080', 'crossfade' => 1.25],
            new RenderOptions(4.5, 25, '1920x1080', 1.25)->toArray(),
        );
    }
}
