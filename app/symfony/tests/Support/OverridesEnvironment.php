<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Sets an environment variable for one test and puts it back afterwards.
 *
 * The container resolves %env()% at runtime, so a feature switched on by an
 * environment variable is tested by setting it before the kernel boots. What
 * matters is the putting back: the variables come from .env, and a test that
 * simply unset one would leave every later test in the process facing
 * "Environment variable not found".
 */
trait OverridesEnvironment
{
    /** @var array<string, string|null> name => what it was */
    private array $originalEnvironment = [];

    protected function overrideEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->originalEnvironment)) {
            $existing = $_ENV[$name] ?? null;
            $this->originalEnvironment[$name] = \is_string($existing) ? $existing : null;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function restoreEnvironment(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $this->originalEnvironment = [];
    }
}
