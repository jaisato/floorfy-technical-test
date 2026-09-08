<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

use App\Shared\Application\Redaction\Urls;
use App\Shared\Domain\Exception\PermanentFailure;

/**
 * A notification that did not reach its endpoint.
 *
 * Whether it is worth retrying is part of the failure: a server that answered
 * 503 may answer 200 in a minute, while a URL the guard refuses will be refused
 * every time and must not spend the retries.
 *
 * The message names the endpoint with its credentials taken out. A callback URL
 * is a client's, and carrying a token in the userinfo or a query parameter is
 * the ordinary way to write one; the message goes to the application log and,
 * once the retries are spent, into the failure transport, where it is readable
 * by anyone who operates either. The task id is logged as its own field, so
 * nothing is lost by dropping the query.
 *
 * That applies to what a lower layer said as well. Symfony's transport
 * exceptions quote the whole request URL back - `... for
 * "https://bot:s3cr3t@client.example/hook?token=…"` - so a cause's message is
 * scrubbed the same way before it is repeated here, and the cause is named by
 * class rather than chained: a previous exception travels into the failure
 * transport with its message intact, which would put back exactly what the
 * scrub took out.
 */
final class CallbackDeliveryFailed extends \RuntimeException
{
    private function __construct(string $message, private readonly bool $permanent)
    {
        parent::__construct($message);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    /**
     * The guard would not let the notification be sent.
     *
     * Permanent only when the guard's answer is: a scheme, a port or a private
     * address is a property of the URL, and the twentieth attempt reaches the
     * first one's conclusion. A host that did not resolve is a property of the
     * moment, and calling it permanent marked the callback abandoned - which is
     * how the recovery sweep is told to stop offering the task - so a few
     * seconds of resolver trouble silently cost the client every notification
     * owed during it.
     */
    public static function refused(string $url, \Throwable $cause): self
    {
        return new self(\sprintf('La URL de callback fue rechazada: %s', self::explain($cause)), $cause instanceof PermanentFailure);
    }

    public static function unsigned(): self
    {
        return new self('No hay secreto de firma configurado (CALLBACK_SIGNING_SECRET); las notificaciones no se envían sin firmar.', true);
    }

    public static function status(string $url, int $status): self
    {
        return new self(\sprintf('El endpoint %s respondió HTTP %d.', self::redact($url), $status), false);
    }

    public static function unreachable(string $url, \Throwable $cause): self
    {
        return new self(\sprintf('No se pudo entregar la notificación a %s: %s', self::redact($url), self::explain($cause)), false);
    }

    /**
     * What the lower layer said, with every URL in it redacted, and the class
     * that said it - the part of the lost exception chain worth keeping.
     */
    private static function explain(\Throwable $cause): string
    {
        return \sprintf('%s (%s)', Urls::scrub($cause->getMessage()), get_debug_type($cause));
    }

    private static function redact(string $url): string
    {
        return Urls::endpoint($url);
    }
}
