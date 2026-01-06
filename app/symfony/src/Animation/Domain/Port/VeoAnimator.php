<?php
declare(strict_types=1);

namespace App\Animation\Domain\Port;

use App\Shared\Domain\ValueObject\UrlValue;

interface VeoAnimator
{
    /**
     * Devuelve el operationName (string) para poder hacer polling.
     */
    public function requestAnimation(UrlValue $imageUrl, string $prompt, ?int $seed = null): string;

    public function pollAnimation(string $operationName): VeoResult;
}
