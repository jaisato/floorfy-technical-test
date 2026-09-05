<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Reads values out of a command line built as an argv array.
 */
final class Argv
{
    /**
     * The value that follows a flag, e.g. "error" for "-loglevel".
     *
     * @param list<string> $command
     */
    public static function after(array $command, string $flag): string
    {
        $index = array_search($flag, $command, true);

        Assert::assertIsInt($index, \sprintf('the command does not contain "%s"', $flag));
        Assert::assertArrayHasKey($index + 1, $command, \sprintf('"%s" has no value after it', $flag));

        return $command[$index + 1];
    }

    /** @param list<string> $command */
    public static function output(array $command): string
    {
        $last = array_key_last($command);

        Assert::assertIsInt($last, 'the command is empty');

        return $command[$last];
    }
}
