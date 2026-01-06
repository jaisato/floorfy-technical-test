<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

interface PublicStorage
{
    /**
     * Guarda contenido binario y devuelve la URL pública (relativa) para servirlo por nginx.
     */
    public function put(string $relativePath, string $binary, string $mimeType): string;

    /**
     * Crea un path relativo seguro (ej: "videos/uuid.mp4").
     */
    public function generatePath(string $prefix, string $extension): string;

    /**
     * Convierte un path relativo a una URL pública (ej: "/storage/videos/x.mp4").
     */
    public function publicUrl(string $relativePath): string;
}
