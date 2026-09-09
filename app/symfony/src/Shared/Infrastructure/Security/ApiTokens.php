<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The API keys the deployment accepts, read from API_TOKENS.
 *
 * Format: `name:secret,other:secret`. The name identifies the client - in the
 * log, and as the scope of its Idempotency-Keys - and the secret is what the
 * client sends. An empty value means the API is open, which is the default:
 * turning authentication on must be a deliberate act, not something a missing
 * variable does by accident, and the test instructions for this project assume
 * an open API.
 */
final readonly class ApiTokens
{
    /**
     * Short secrets are the ones that get guessed. Sixteen characters is not a
     * policy, it is a floor below which the feature is decoration.
     */
    public const int MINIMUM_SECRET_LENGTH = 16;

    /** @var array<string, string> secret => client name */
    private array $clientsBySecret;

    public function __construct(
        #[Autowire(env: 'API_TOKENS')]
        string $tokens,
    ) {
        $this->clientsBySecret = self::parse($tokens);
    }

    /** While this is false, nothing is authenticated and the API is open. */
    public function enabled(): bool
    {
        return [] !== $this->clientsBySecret;
    }

    /** The client that owns this secret, or null. */
    public function clientFor(#[\SensitiveParameter] string $secret): ?string
    {
        // A comparison that takes the same time whatever the secret is: the
        // obvious `$map[$secret] ?? null` leaks nothing here (hash lookup), but
        // this keeps that true if the store ever stops being an array.
        //
        // The key comes back as an int when the secret is made of digits only
        // (PHP converts such a string key on assignment), and hash_equals()
        // takes strings: without the cast one numeric secret in API_TOKENS was
        // a TypeError on every request.
        foreach ($this->clientsBySecret as $known => $client) {
            if (hash_equals((string) $known, $secret)) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> secret => client name
     */
    private static function parse(#[\SensitiveParameter] string $tokens): array
    {
        $parsed = [];

        foreach (explode(',', $tokens) as $entry) {
            $entry = trim($entry);

            if ('' === $entry) {
                continue;
            }

            $separator = strpos($entry, ':');

            if (false === $separator || 0 === $separator) {
                throw new \InvalidArgumentException('API_TOKENS: cada entrada debe tener la forma "nombre:secreto".');
            }

            $name = substr($entry, 0, $separator);
            $secret = substr($entry, $separator + 1);

            if (\strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
                throw new \InvalidArgumentException(\sprintf('API_TOKENS: el secreto de "%s" tiene menos de %d caracteres.', $name, self::MINIMUM_SECRET_LENGTH));
            }

            if (isset($parsed[$secret])) {
                throw new \InvalidArgumentException('API_TOKENS: dos clientes comparten el mismo secreto.');
            }

            $parsed[$secret] = $name;
        }

        return $parsed;
    }
}
