<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged so a test can assert that the detail an exception was
 * not allowed to show the client ended up somewhere.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** The concatenation of every logged message and its context values. */
    public function everythingLogged(): string
    {
        $text = '';

        foreach ($this->records as $record) {
            $text .= $record['message'];

            foreach ($record['context'] as $value) {
                if (\is_scalar($value) || $value instanceof \Stringable) {
                    $text .= ' '.$value;
                }
            }

            $text .= "\n";
        }

        return $text;
    }
}
