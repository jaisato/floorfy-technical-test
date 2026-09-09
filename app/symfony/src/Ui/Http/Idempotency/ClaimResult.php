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
        /**
         * Names the attempt that holds the claim; only a CLAIMED result has
         * one. The writes that end the request hand it back, and a claim
         * that changed hands in the meantime refuses them.
         */
        public ?string $token,
    ) {
    }

    public static function claimed(string $token): self
    {
        return new self(ClaimOutcome::CLAIMED, null, $token);
    }

    public static function replay(StoredResponse $response): self
    {
        return new self(ClaimOutcome::REPLAY, $response, null);
    }

    public static function mismatch(): self
    {
        return new self(ClaimOutcome::MISMATCH, null, null);
    }

    public static function inProgress(): self
    {
        return new self(ClaimOutcome::IN_PROGRESS, null, null);
    }
}
