<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing the application needs in order to do its work.
 *
 * Implementations are collected by the tag, so adding a dependency to the
 * readiness answer is adding a class - nothing has to be registered by hand.
 */
#[AutoconfigureTag('app.health_check')]
interface HealthCheck
{
    /** Short, stable name; it is the key this check gets in the response. */
    public function name(): string;

    public function check(): CheckResult;
}
