<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use App\Shared\Application\Health\ReadinessProbe;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class ReadinessProbeTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testEverythingPassingIsReady(): void
    {
        $readiness = $this->probe(self::passing('database'), self::passing('ffmpeg'))->run();

        self::assertTrue($readiness->ready);
        self::assertSame(['database' => 'ok', 'ffmpeg' => 'ok'], $readiness->toArray());
        self::assertSame([], $this->logger->records);
    }

    public function testOneFailureIsEnoughToBeUnready(): void
    {
        $readiness = $this->probe(self::passing('database'), self::failing('ffmpeg', 'no such binary'))->run();

        self::assertFalse($readiness->ready);
        self::assertSame(['database' => 'ok', 'ffmpeg' => 'failed'], $readiness->toArray());
    }

    /**
     * The reason names a host, a path or a driver message; the client is told
     * which check is unhappy and nothing else, and the operator reads the log.
     */
    public function testTheReasonIsLoggedAndNotReported(): void
    {
        $readiness = $this->probe(self::failing('database', 'Connection refused on db-1:3306'))->run();

        self::assertSame(['database' => 'failed'], $readiness->toArray());
        self::assertStringContainsString('Connection refused on db-1:3306', $this->logger->everythingLogged());
    }

    /** A broken dependency must not turn the readiness answer into a 500. */
    public function testACheckThatThrowsIsAFailedCheck(): void
    {
        $exploding = new class implements HealthCheck {
            public function name(): string
            {
                return 'transport';
            }

            public function check(): CheckResult
            {
                throw new \RuntimeException('the broker hung up');
            }
        };

        $readiness = $this->probe(self::passing('database'), $exploding)->run();

        self::assertFalse($readiness->ready);
        self::assertSame(['database' => 'ok', 'transport' => 'failed'], $readiness->toArray());
        self::assertStringContainsString('the broker hung up', $this->logger->everythingLogged());
    }

    /** Same body whatever order the container hands the checks over in. */
    public function testTheChecksAreReportedInAStableOrder(): void
    {
        $readiness = $this->probe(self::passing('work_dir'), self::passing('database'), self::passing('ffmpeg'))->run();

        self::assertSame(['database', 'ffmpeg', 'work_dir'], array_keys($readiness->toArray()));
    }

    public function testNoChecksAtAllIsReady(): void
    {
        self::assertTrue($this->probe()->run()->ready);
    }

    private function probe(HealthCheck ...$checks): ReadinessProbe
    {
        return new ReadinessProbe($checks, $this->logger);
    }

    private static function passing(string $name): HealthCheck
    {
        return new readonly class($name) implements HealthCheck {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function check(): CheckResult
            {
                return CheckResult::ok();
            }
        };
    }

    private static function failing(string $name, string $reason): HealthCheck
    {
        return new readonly class($name, $reason) implements HealthCheck {
            public function __construct(private string $name, private string $reason)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function check(): CheckResult
            {
                return CheckResult::failed($this->reason);
            }
        };
    }
}
