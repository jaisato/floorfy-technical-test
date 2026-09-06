<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\DatabaseCheck;
use App\Shared\Infrastructure\Health\FfmpegCheck;
use App\Shared\Infrastructure\Health\TransportCheck;
use App\Shared\Infrastructure\Health\WritableDirectoryCheck;
use App\Tests\Support\RecordingProcessRunner;
use App\Tests\Support\TempDirectory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Each readiness check on its own: what it asks, and what it says when the
 * answer is no.
 */
final class HealthChecksTest extends TestCase
{
    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = new TempDirectory('health');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testTheDatabaseCheckPassesAgainstAWorkingConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $result = new DatabaseCheck($connection)->check();

        self::assertTrue($result->ok);
        self::assertNull($result->reason);
    }

    public function testTheDatabaseCheckFailsWhenTheServerCannotBeReached(): void
    {
        // A path under a file, so opening it cannot succeed.
        $file = $this->temp->file('not-a-directory');
        file_put_contents($file, 'x');

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file.'/db.sqlite']);

        $result = new DatabaseCheck($connection)->check();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }

    public function testTheFfmpegCheckAsksTheBinaryForItsVersion(): void
    {
        $runner = new RecordingProcessRunner();

        $result = new FfmpegCheck($runner, 5)->check();

        self::assertTrue($result->ok);
        self::assertSame(['ffmpeg', '-hide_banner', '-version'], $runner->lastCommand());
        self::assertSame(5, $runner->runs[0]['timeout']);
    }

    public function testTheFfmpegCheckFailsWhenTheBinaryIsMissing(): void
    {
        $runner = new RecordingProcessRunner();
        $runner->willFail('exec: ffmpeg: not found');

        $result = new FfmpegCheck($runner, 5)->check();

        self::assertFalse($result->ok);
        self::assertStringContainsString('ffmpeg: not found', (string) $result->reason);
    }

    public function testAWritableDirectoryPasses(): void
    {
        $result = new WritableDirectoryCheck('videos_dir', $this->temp->path)->check();

        self::assertTrue($result->ok);
    }

    /** The worker creates it too; a missing volume mount point is not a fault. */
    public function testAMissingDirectoryIsCreated(): void
    {
        $path = $this->temp->file('videos/nested');

        $result = new WritableDirectoryCheck('videos_dir', $path)->check();

        self::assertTrue($result->ok);
        self::assertDirectoryExists($path);
    }

    public function testADirectoryThatCannotBeWrittenFails(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root writes to a read-only directory anyway.');
        }

        $path = $this->temp->file('locked');
        mkdir($path, 0o500);

        $result = new WritableDirectoryCheck('work_dir', $path)->check();

        self::assertFalse($result->ok);
        self::assertStringContainsString($path, (string) $result->reason);
    }

    public function testADirectoryThatCannotBeCreatedFails(): void
    {
        $file = $this->temp->file('a-file');
        file_put_contents($file, 'x');

        $result = new WritableDirectoryCheck('work_dir', $file.'/under-a-file')->check();

        self::assertFalse($result->ok);
        self::assertStringContainsString('No se pudo crear', (string) $result->reason);
    }

    public function testTheCheckKeepsTheNameItWasGiven(): void
    {
        self::assertSame('videos_dir', new WritableDirectoryCheck('videos_dir', $this->temp->path)->name());
        self::assertSame('ffmpeg', new FfmpegCheck(new RecordingProcessRunner(), 5)->name());
    }

    /** A transport that cannot be counted has nothing to be unreachable. */
    public function testATransportWithoutACountPasses(): void
    {
        $transport = new InMemoryTransportFactory()->createTransport('in-memory://', [], new PhpSerializer());

        $check = new TransportCheck($transport);

        self::assertSame('transport', $check->name());
        self::assertTrue($check->check()->ok);
    }
}
