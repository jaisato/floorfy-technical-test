<?php

declare(strict_types=1);

namespace App\Ui\Http;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Who is making the current request, as far as the API knows.
 *
 * With API_TOKENS set, that is the name the caller's secret resolves to; with
 * the API open, nobody. Everything that depends on the caller's identity - the
 * scope of an Idempotency-Key, for one - asks this class rather than reaching
 * into the security token itself, so there is one place that decides what
 * "anonymous" means.
 */
final readonly class RequestActor
{
    public const string ANONYMOUS = 'anonymous';

    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function identifier(): ?string
    {
        $identifier = $this->tokens->getToken()?->getUserIdentifier();

        return null === $identifier || '' === $identifier ? null : $identifier;
    }

    /** A name for the caller that is never empty. */
    public function scope(): string
    {
        return $this->identifier() ?? self::ANONYMOUS;
    }
}
