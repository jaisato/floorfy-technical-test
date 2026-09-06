<?php

declare(strict_types=1);

namespace App\Tests\Ui\Console;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Ui\Console\PruneVideosCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The retention command as an operator runs it, against the real container.
 */
final class PruneVideosCommandTest extends KernelTestCase
{
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM partial_videos');
        $connection->executeStatement('DELETE FROM video_tasks');
        $entityManager->clear();

        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $this->command = new CommandTester(new Application($kernel)->find('app:videos:prune'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->videosDirectory().'/.staging/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Where the deployment under test puts its videos. */
    private function videosDirectory(): string
    {
        $directory = self::getContainer()->getParameter('app.videos_dir');
        self::assertIsString($directory);

        return $directory;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableWindows(): iterable
    {
        yield 'no unit' => ['7'];
        yield 'unknown unit' => ['7y'];
        yield 'not a number' => ['a week'];
        yield 'zero' => ['0d'];
        yield 'empty' => [''];
    }

    /** A window nobody meant must not be read as "everything". */
    #[DataProvider('unusableWindows')]
    public function testAWindowThatIsNotAWindowIsRefused(string $window): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--older-than' => $window]));
        self::assertStringContainsString('ventana', $this->command->getDisplay());
    }

    public function testALimitBelowOneIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--limit' => '0']));
        self::assertStringContainsString('límite', $this->command->getDisplay());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function windows(): iterable
    {
        yield 'minutes' => ['30m', 1800];
        yield 'hours' => ['12h', 43200];
        yield 'days' => ['7d', 604800];
        yield 'weeks' => ['2w', 1209600];
    }

    #[DataProvider('windows')]
    public function testEveryUnitIsUnderstood(string $window, int $expectedSeconds): void
    {
        self::assertSame($expectedSeconds, PruneVideosCommand::secondsIn($window));
    }

    public function testAnEmptyDatabaseIsReportedAsNothingToDo(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute([]));
        self::assertStringContainsString('No hay nada', $this->command->getDisplay());
    }

    /**
     * A run that swept nothing but staging still freed bytes, and saying
     * "nothing to delete" over them would send whoever reads the cron mail
     * looking for a volume that is quietly filling.
     */
    public function testAStagingSweepWithNoTasksDueIsStillReported(): void
    {
        $staging = $this->videosDirectory().'/.staging';

        if (!is_dir($staging)) {
            mkdir($staging, 0o775, true);
        }

        $orphan = $staging.'/final_dead-worker.mp4';
        file_put_contents($orphan, 'half a video');
        touch($orphan, strtotime('-30 days'));

        self::assertSame(Command::SUCCESS, $this->command->execute(['--older-than' => '1d']));
        self::assertStringContainsString('0 tarea(s), 1 fichero(s)', $this->command->getDisplay());
        self::assertFileDoesNotExist($orphan);
    }

    public function testAnOldTaskIsPrunedAndReported(): void
    {
        $taskId = $this->oldCompletedTask();

        self::assertSame(Command::SUCCESS, $this->command->execute(['--older-than' => '1d']));
        self::assertStringContainsString('Borradas 1 tarea', $this->command->getDisplay());

        $task = $this->taskRepository()->get(UuidValue::fromString($taskId));

        self::assertNotNull($task);
        self::assertNull($task->finalVideoUrl());
        self::assertNotNull($task->prunedAt());
    }

    public function testADryRunSaysSoAndChangesNothing(): void
    {
        $taskId = $this->oldCompletedTask();

        self::assertSame(Command::SUCCESS, $this->command->execute(['--older-than' => '1d', '--dry-run' => true]));
        self::assertStringContainsString('Se borrarían', $this->command->getDisplay());

        self::assertNull($this->taskRepository()->get(UuidValue::fromString($taskId))?->prunedAt());
    }

    /** The default window is documented as a week; a three-day-old task is inside it. */
    public function testTheDefaultWindowIsAWeek(): void
    {
        $this->completedTaskFinished('-3 days');

        self::assertSame(Command::SUCCESS, $this->command->execute([]));
        self::assertStringContainsString('No hay nada', $this->command->getDisplay());

        self::assertSame(Command::SUCCESS, $this->command->execute(['--older-than' => '2d']));
        self::assertStringContainsString('Borradas 1 tarea', $this->command->getDisplay());
    }

    /** Which tasks went is worth knowing; it is just not worth printing always. */
    public function testTheTaskIdsAreListedWhenAskedFor(): void
    {
        $taskId = $this->oldCompletedTask();

        $this->command->execute(['--older-than' => '1d'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString($taskId, $this->command->getDisplay());
    }

    /** Long past any window a test would use. */
    private function oldCompletedTask(): string
    {
        return $this->completedTaskFinished('2020-01-01T00:00:00+00:00');
    }

    private function completedTaskFinished(string $when): string
    {
        $at = DateTimeValue::fromDateTimeImmutable(new \DateTimeImmutable($when, new \DateTimeZone('UTC')));
        $task = VideoTask::create(['images' => []], $at);
        $task->markProcessing($at);
        $task->markCompleted('/videos/final_'.$task->id()->value.'.mp4', $at);
        $this->taskRepository()->save($task);

        return $task->id()->value;
    }

    private function taskRepository(): VideoTaskRepository
    {
        $tasks = self::getContainer()->get(VideoTaskRepository::class);

        self::assertInstanceOf(VideoTaskRepository::class, $tasks);

        return $tasks;
    }
}
