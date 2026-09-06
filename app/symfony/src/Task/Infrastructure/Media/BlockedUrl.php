<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use App\Shared\Domain\Exception\ClientSafe;
use App\Shared\Domain\Exception\PermanentFailure;

/**
 * The URL policy refused an image, for what the URL is rather than for
 * anything that happened: its scheme, its port, the address its host resolves
 * to, the size or the media type it serves. Permanent, therefore - the next
 * attempt reaches the same conclusion.
 */
final class BlockedUrl extends \RuntimeException implements ClientSafe, PermanentFailure
{
    public static function malformed(string $url): self
    {
        return new self(\sprintf('URL de imagen malformada: %s', $url));
    }

    public static function scheme(string $scheme): self
    {
        return new self(\sprintf('Esquema de URL no permitido: "%s". Sólo se admiten http y https.', $scheme));
    }

    public static function port(int $port): self
    {
        return new self(\sprintf('Puerto no permitido: %d. Sólo se admiten 80 y 443.', $port));
    }

    public static function privateAddress(string $host, string $ip): self
    {
        return new self(\sprintf('El host "%s" resuelve a una dirección no pública (%s).', $host, $ip));
    }

    public static function unresolvable(string $host): self
    {
        return new self(\sprintf('No se pudo resolver el host "%s".', $host));
    }

    public static function tooManyRedirects(string $url): self
    {
        return new self(\sprintf('Demasiadas redirecciones al descargar %s', $url));
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self(\sprintf('La imagen supera el tamaño máximo permitido (%d bytes).', $limitBytes));
    }

    public static function notAnImage(string $mediaType): self
    {
        return new self(\sprintf('El recurso descargado no es una imagen (%s).', '' === $mediaType ? 'tipo desconocido' : $mediaType));
    }
}
