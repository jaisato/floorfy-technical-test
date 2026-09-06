<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * What one readiness check found.
 *
 * The reason a check failed names hosts, paths and driver messages, so it goes
 * to the log and never into the response: /health/ready is reachable without
 * authentication, and an unauthenticated caller learns nothing from it beyond
 * which check is unhappy.
 */
final readonly class CheckResult
{
    private function __construct(
        public bool $ok,
        public ?string $reason,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
