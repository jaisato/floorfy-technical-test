<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Infrastructure\Media\PublicTargetPolicy;
use App\Task\Infrastructure\Media\TargetPolicy;

/**
 * Lets the download tests talk to a server the test itself started on
 * 127.0.0.1, and to nothing else.
 *
 * This exists only in the test suite, on purpose. An environment variable that
 * relaxed the production guard would be one deployment mistake away from
 * turning the API into a proxy for the private network; a test double cannot be
 * switched on in production because it is not shipped.
 *
 * Everything that is not loopback still faces the real rules, so a test cannot
 * accidentally reach the internet either.
 */
final readonly class LoopbackTargetPolicy implements TargetPolicy
{
    private PublicTargetPolicy $production;

    public function __construct()
    {
        $this->production = new PublicTargetPolicy();
    }

    public function allowsAddress(string $ip): bool
    {
        return '127.0.0.1' === $ip || '::1' === $ip || $this->production->allowsAddress($ip);
    }

    public function allowsPort(int $port): bool
    {
        // The built-in server is handed an ephemeral port.
        return true;
    }
}
