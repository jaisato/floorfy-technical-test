<?php

declare(strict_types=1);

namespace App\Ui\Http\Security;

use App\Shared\Infrastructure\Security\ApiTokens;
use App\Ui\Http\Response\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Shared-secret authentication, switched on by setting API_TOKENS.
 *
 * The secret travels either as `Authorization: Bearer <secret>` or as
 * `X-API-Key: <secret>`; the first is what most clients already do, the second
 * is what most consoles and dashboards let you paste. The client's *name* is
 * never sent - it is what the secret resolves to, and what the audit trail and
 * the scope of an Idempotency-Key then use.
 *
 * With no tokens configured the API is open: supports() says no and the request
 * is never authenticated. That is the default, so an existing deployment does
 * not start refusing traffic because a dependency was upgraded.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public const string HEADER = 'X-API-Key';

    public function __construct(private readonly ApiTokens $tokens)
    {
    }

    public function supports(Request $request): bool
    {
        return $this->tokens->enabled();
    }

    public function authenticate(Request $request): Passport
    {
        $secret = self::secretFrom($request);

        // The same message whether nothing was sent or the wrong thing was:
        // "no such key" and "you sent no key" are different facts, and only one
        // of them is any of the caller's business.
        $client = null === $secret ? null : $this->tokens->clientFor($secret);

        if (null === $client) {
            throw new CustomUserMessageAuthenticationException('Se necesita una credencial de API válida.');
        }

        return new SelfValidatingPassport(new UserBadge($client, static fn (string $name): InMemoryUser => new InMemoryUser($name, null, ['ROLE_API_CLIENT'])));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return ApiProblem::response(
            Response::HTTP_UNAUTHORIZED,
            'Se necesita una credencial de API válida.',
            [],
            // Which schemes to try; the client is not told which one it got wrong.
            ['WWW-Authenticate' => 'Bearer realm="api", X-API-Key realm="api"'],
        );
    }

    private static function secretFrom(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (\is_string($authorization) && 1 === preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return $matches[1];
        }

        $apiKey = $request->headers->get(self::HEADER);

        return \is_string($apiKey) && '' !== $apiKey ? $apiKey : null;
    }
}
