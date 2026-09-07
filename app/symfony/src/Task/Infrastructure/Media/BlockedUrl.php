<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\Exception\PermanentFailure;

/**
 * The URL policy refused an image, for what the URL is rather than for
 * anything that happened: its scheme, its port, the address its host resolves
 * to, the size or the media type it serves. Permanent, therefore - the next
 * attempt reaches the same conclusion. A host that would not resolve is not
 * one of these: see UnresolvableHost.
 *
 * A message that names the URL names it the way a log line does, through
 * `Urls::endpoint()`. These messages are client-safe, which here means they are
 * stored on the partial and served back as its `error`: an image URL is
 * routinely presigned, and echoing it whole handed the signature to whoever can
 * read the task - the same credential `image_url` is deliberately redacted to
 * keep out of that response.
 */
final class BlockedUrl extends \RuntimeException implements PermanentFailure, UrlNotFetchable
{
    public static function malformed(string $url): self
    {
        return new self(\sprintf('URL de imagen malformada: %s', Urls::endpoint($url)));
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

    public static function tooManyRedirects(string $url): self
    {
        return new self(\sprintf('Demasiadas redirecciones al descargar %s', Urls::endpoint($url)));
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
