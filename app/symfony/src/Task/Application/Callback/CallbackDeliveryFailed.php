<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

/**
 * A notification that did not reach its endpoint.
 *
 * Whether it is worth retrying is part of the failure: a server that answered
 * 503 may answer 200 in a minute, while a URL the guard refuses will be refused
 * every time and must not spend the retries.
 */
final class CallbackDeliveryFailed extends \RuntimeException
{
    private function __construct(string $message, private readonly bool $permanent, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public static function refused(string $url, \Throwable $cause): self
    {
        return new self(\sprintf('La URL de callback fue rechazada: %s', $cause->getMessage()), true, $cause);
    }

    public static function unsigned(): self
    {
        return new self('No hay secreto de firma configurado (CALLBACK_SIGNING_SECRET); las notificaciones no se envían sin firmar.', true);
    }

    public static function status(string $url, int $status): self
    {
        return new self(\sprintf('El endpoint %s respondió HTTP %d.', $url, $status), false);
    }

    public static function unreachable(string $url, \Throwable $cause): self
    {
        return new self(\sprintf('No se pudo entregar la notificación a %s: %s', $url, $cause->getMessage()), false, $cause);
    }
}
