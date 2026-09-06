<?php

declare(strict_types=1);

namespace App\Ui\Console;

use App\Shared\Application\Clock\Clock;
use App\Task\Application\Retention\PruneVideos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The retention job: deletes the videos of tasks nobody has asked about in a
 * while, and the scratch directories that went with them.
 *
 * Meant for cron. --dry-run reports what a real run would delete and touches
 * nothing, which is how you find out whether a window is the one you meant
 * before it removes a month of videos.
 */
#[AsCommand(name: 'app:videos:prune', description: 'Borra los vídeos de las tareas terminadas hace más de la ventana de retención.', help: <<<'TXT'
    Borra el vídeo final, los clips parciales y el directorio de trabajo de las
    tareas <info>completed</info>, <info>failed</info> o <info>canceled</info> cuya
    última actualización sea anterior a la ventana. La tarea <comment>no</comment>
    se borra: se queda con <info>final_video_url</info> a null y con
    <info>pruned_at</info>.

    En cron, una vez al día:

      <info>0 4 * * * docker compose exec -T php php bin/console app:videos:prune --older-than=30d</info>
    TXT)]
final class PruneVideosCommand extends Command
{
    private const string DEFAULT_WINDOW = '7d';

    public function __construct(
        private readonly PruneVideos $prune,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Ventana de retención: <n>m, <n>h, <n>d o <n>w.', self::DEFAULT_WINDOW)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enumera lo que se borraría, sin borrar nada.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de tareas por ejecución.', (string) PruneVideos::DEFAULT_LIMIT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $seconds = self::secondsIn((string) $input->getOption('older-than'));

        if (null === $seconds) {
            $io->error('La ventana debe ser un número seguido de m, h, d o w (por ejemplo 7d).');

            return Command::INVALID;
        }

        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('El límite debe ser un entero positivo.');

            return Command::INVALID;
        }

        $before = $this->clock->now()->minusSeconds($seconds);
        $report = $this->prune->run($before, (bool) $input->getOption('dry-run'), $limit);

        if (0 === $report->tasks()) {
            $io->success(\sprintf('No hay tareas anteriores a %s con vídeos que borrar.', $before->toIso8601()));

            return Command::SUCCESS;
        }

        $io->writeln($report->taskIds, OutputInterface::VERBOSITY_VERBOSE);
        $io->success(\sprintf(
            '%s %d tarea(s), %d fichero(s), %s.',
            $report->dryRun ? 'Se borrarían' : 'Borradas',
            $report->tasks(),
            $report->files,
            self::humanBytes($report->bytes),
        ));

        return Command::SUCCESS;
    }

    /** @return positive-int|null */
    public static function secondsIn(string $window): ?int
    {
        if (1 !== preg_match('/^(\d+)([mhdw])$/', trim($window), $matches)) {
            return null;
        }

        $seconds = (int) $matches[1] * match ($matches[2]) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
        };

        return $seconds > 0 ? $seconds : null;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }
}
