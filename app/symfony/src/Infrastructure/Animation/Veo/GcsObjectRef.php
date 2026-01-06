<?php

declare(strict_types=1);

namespace App\Infrastructure\Animation\Veo;

final class GcsObjectRef
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $object,
    ) {
    }

    public static function fromUri(string $gcsUri): self
    {
        if (!str_starts_with($gcsUri, 'gs://')) {
            throw new \InvalidArgumentException('No es un GCS URI válido: '.$gcsUri);
        }

        $withoutScheme = substr($gcsUri, 5); // quita gs://
        $parts = explode('/', $withoutScheme, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('No es un GCS URI válido: '.$gcsUri);
        }

        return new self($parts[0], $parts[1]);
    }
}
