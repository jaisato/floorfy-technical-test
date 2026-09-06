<?php

declare(strict_types=1);

namespace App\Task\Domain\ValueObject;

use App\Task\Domain\Exception\InvalidRenderOptions;

/**
 * How a task is rendered: how long each clip lasts, at what frame rate and
 * size, and how long the fade between clips takes.
 *
 * Frame rate and size are per task rather than per image on purpose. The parts
 * of a task are concatenated by stream copy when there is no crossfade, and
 * that only works while every part shares its codec parameters; letting one
 * image be 1080p at 24 fps inside a 720p 30 fps task would produce a file that
 * plays wrong or not at all. Duration is the one that can vary per image, and
 * it does.
 */
final readonly class RenderOptions
{
    public const float MIN_DURATION = 1.0;
    public const float MAX_DURATION = 15.0;
    public const float MAX_CROSSFADE = 2.0;

    /** @var list<int> */
    public const array FRAME_RATES = [24, 25, 30];

    /** @var list<string> */
    public const array RESOLUTIONS = ['1280x720', '1920x1080'];

    public function __construct(
        /** Seconds each clip lasts, unless the image says otherwise. */
        public float $duration,
        public int $fps,
        /** One of RESOLUTIONS, as "<width>x<height>". */
        public string $resolution,
        /** Seconds of fade between consecutive clips; 0 is a hard cut. */
        public float $crossfade,
    ) {
        // The UI layer refuses these values long before they get here; this is
        // the backstop that keeps an impossible ffmpeg command from being built
        // out of a row somebody edited by hand.
        if ($duration < self::MIN_DURATION || $duration > self::MAX_DURATION) {
            throw InvalidRenderOptions::duration($duration);
        }

        if (!\in_array($fps, self::FRAME_RATES, true)) {
            throw InvalidRenderOptions::fps($fps);
        }

        if (!\in_array($resolution, self::RESOLUTIONS, true)) {
            throw InvalidRenderOptions::resolution($resolution);
        }

        if ($crossfade < 0.0 || $crossfade > self::MAX_CROSSFADE) {
            throw InvalidRenderOptions::crossfade($crossfade);
        }
    }

    public function width(): int
    {
        return (int) explode('x', $this->resolution)[0];
    }

    public function height(): int
    {
        return (int) explode('x', $this->resolution)[1];
    }

    public function hasCrossfade(): bool
    {
        return $this->crossfade > 0.0;
    }

    /** The same options with another clip length; the rest is per task. */
    public function withDuration(float $duration): self
    {
        return new self($duration, $this->fps, $this->resolution, $this->crossfade);
    }

    /**
     * Applies whatever a request asked for on top of these, which are the
     * deployment's defaults. An option that was not sent keeps its default.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            self::float($overrides['duration'] ?? null) ?? $this->duration,
            self::int($overrides['fps'] ?? null) ?? $this->fps,
            \is_string($overrides['resolution'] ?? null) ? $overrides['resolution'] : $this->resolution,
            self::float($overrides['crossfade'] ?? null) ?? $this->crossfade,
        );
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            self::float($stored['duration'] ?? null) ?? self::MIN_DURATION,
            self::int($stored['fps'] ?? null) ?? self::FRAME_RATES[0],
            \is_string($stored['resolution'] ?? null) ? $stored['resolution'] : self::RESOLUTIONS[0],
            self::float($stored['crossfade'] ?? null) ?? 0.0,
        );
    }

    /** @return array{duration: float, fps: int, resolution: string, crossfade: float} */
    public function toArray(): array
    {
        return [
            'duration' => $this->duration,
            'fps' => $this->fps,
            'resolution' => $this->resolution,
            'crossfade' => $this->crossfade,
        ];
    }

    private static function float(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
