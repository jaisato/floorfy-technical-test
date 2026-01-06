<?php
declare(strict_types=1);

namespace App\Animation\Domain\Port;

interface ObjectStorage
{
    /** Descarga desde URL externa y la guarda en storage propio (evitas depender de URLs temporales de terceros). */
    public function importFromUrl(string $sourceUrl, string $targetPath): string;

    /** Devuelve URL pública (o firmada) del objeto */
    public function publicUrl(string $path): string;
}
