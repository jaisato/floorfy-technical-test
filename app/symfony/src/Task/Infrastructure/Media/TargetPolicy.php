<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

/**
 * Decides which network endpoints this service may fetch from.
 *
 * Separated from PublicUrlGuard so the rules can be swapped for a test that has
 * to talk to a server on 127.0.0.1. Production has exactly one implementation,
 * PublicTargetPolicy, and it is the constructor default everywhere; there is
 * deliberately no configuration option that relaxes it, because an SSRF guard
 * with an off switch is an SSRF guard one environment variable from useless.
 */
interface TargetPolicy
{
    public function allowsAddress(string $ip): bool;

    public function allowsPort(int $port): bool;
}
