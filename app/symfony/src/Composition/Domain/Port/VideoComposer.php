<?php
declare(strict_types=1);

namespace App\Composition\Domain\Port;

interface VideoComposer
{
    /**
     * @param list<string> $videoUrls URLs accesibles (o paths locales)
     * @return string path local al output mp4 generado
     */
    public function compose(array $videoUrls, string $outputBasename): string;
}
