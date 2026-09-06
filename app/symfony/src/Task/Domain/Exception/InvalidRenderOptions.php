<?php

declare(strict_types=1);

namespace App\Task\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;
use App\Task\Domain\ValueObject\RenderOptions;

/**
 * Render options that cannot produce a video.
 *
 * The API refuses these with a 400 before they get anywhere near here; this is
 * what a row edited by hand, or a future caller that skips the DTO, runs into.
 */
final class InvalidRenderOptions extends DomainException
{
    public static function duration(float $seconds): self
    {
        return new self(\sprintf('La duración debe estar entre %s y %s segundos; se recibió %s.', RenderOptions::MIN_DURATION, RenderOptions::MAX_DURATION, $seconds));
    }

    public static function fps(int $fps): self
    {
        return new self(\sprintf('Los fotogramas por segundo deben ser %s; se recibió %d.', implode(', ', RenderOptions::FRAME_RATES), $fps));
    }

    public static function resolution(string $resolution): self
    {
        return new self(\sprintf('La resolución debe ser %s; se recibió "%s".', implode(' o ', RenderOptions::RESOLUTIONS), $resolution));
    }

    public static function crossfade(float $seconds): self
    {
        return new self(\sprintf('El fundido debe estar entre 0 y %s segundos; se recibió %s.', RenderOptions::MAX_CROSSFADE, $seconds));
    }
}
