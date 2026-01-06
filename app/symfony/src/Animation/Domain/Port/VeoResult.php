<?php
declare(strict_types=1);

namespace App\Animation\Domain\Port;

use App\Animation\Domain\Enum\AnimationStatus;

final readonly class VeoResult
{
    private function __construct(
        public AnimationStatus $status,
        public ?string $videoUrl,     // si algún día devuelve https directo
        public ?string $gcsUri,       // lo normal en Vertex AI: gs://bucket/path.mp4
        public ?string $errorMessage,
    ) {}

    public static function running(): self
    {
        return new self(AnimationStatus::RUNNING, null, null, null);
    }

    public static function succeeded(?string $videoUrl, ?string $gcsUri): self
    {
        return new self(AnimationStatus::SUCCEEDED, $videoUrl, $gcsUri, null);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(AnimationStatus::FAILED, null, null, $errorMessage);
    }
}
