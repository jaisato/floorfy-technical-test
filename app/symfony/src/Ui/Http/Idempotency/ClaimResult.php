<?php

declare(strict_types=1);

namespace App\Ui\Http\Idempotency;

/**
 * Outcome of claiming an Idempotency-Key.
 */
final readonly class ClaimResult
{
    private function __construct(
        public ClaimOutcome $outcome,
        public ?StoredResponse $response,
    ) {
    }

    public static function claimed(): self
    {
        return new self(ClaimOutcome::CLAIMED, null);
    }

    public static function replay(StoredResponse $response): self
    {
        return new self(ClaimOutcome::REPLAY, $response);
    }

    public static function mismatch(): self
    {
        return new self(ClaimOutcome::MISMATCH, null);
    }

    public static function inProgress(): self
    {
        return new self(ClaimOutcome::IN_PROGRESS, null);
    }
}
