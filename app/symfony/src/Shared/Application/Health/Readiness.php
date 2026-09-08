<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * The outcome of every readiness check, and whether they all passed.
 */
final readonly class Readiness
{
    public bool $ready;

    /** @param array<string, CheckResult> $checks name => outcome */
    public function __construct(public array $checks)
    {
        $failed = array_filter($checks, static fn (CheckResult $result): bool => !$result->ok);

        $this->ready = [] === $failed;
    }

    /**
     * The shape served to a client: one word per check, no reasons.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_map(static fn (CheckResult $result): string => $result->ok ? 'ok' : 'failed', $this->checks);
    }

    /** @return array<string, string> name => reason, for the log */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->checks as $name => $result) {
            if (!$result->ok && null !== $result->reason) {
                $failures[$name] = $result->reason;
            }
        }

        return $failures;
    }
}
