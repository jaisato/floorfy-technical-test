<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Process;

use App\Shared\Infrastructure\Process\SymfonyProcessRunner;
use PHPUnit\Framework\TestCase;

final class SymfonyProcessRunnerTest extends TestCase
{
    public function testASuccessfulRunIsReportedWithItsExitCode(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'exit(0);'], 10);

        self::assertTrue($result->successful);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->errorOutput);
    }

    public function testAFailedRunCarriesTheExitCodeAndStderr(): void
    {
        $result = new SymfonyProcessRunner()->run(
            [\PHP_BINARY, '-r', 'fwrite(STDERR, "broken pipe"); exit(3);'],
            10,
        );

        self::assertFalse($result->successful);
        self::assertSame(3, $result->exitCode);
        self::assertStringContainsString('broken pipe', $result->errorOutput);
    }

    /**
     * A timeout is a failed run like any other as far as the caller is
     * concerned. What it must not be is an exception thrown from inside a
     * per-part loop that is meant to carry on with the remaining parts.
     */
    public function testATimeoutIsAFailedRunRatherThanAnException(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'sleep(5);'], 1);

        self::assertFalse($result->successful);
        self::assertStringContainsString('exceeded the timeout', $result->errorOutput);
    }

    /** argv, never a shell string: an argument cannot become another command. */
    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $result = new SymfonyProcessRunner()->run(
            [\PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]);', '--', '; rm -rf /'],
            10,
        );

        self::assertTrue($result->successful);
        self::assertStringContainsString('; rm -rf /', $result->errorOutput);
    }
}
