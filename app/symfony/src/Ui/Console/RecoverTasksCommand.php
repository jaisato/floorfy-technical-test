<?php

declare(strict_types=1);

namespace App\Ui\Console;

use App\Shared\Application\Clock\Clock;
use App\Task\Application\Recovery\RecoverTasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes again the messages the broker never received.
 *
 * A task row and the "process this" message live in two different systems - a
 * database and RabbitMQ - so writing one cannot be part of committing the
 * other. The message is published right after the commit, and a process that
 * dies in that gap leaves a task at "pending" that no worker will ever claim.
 * The same gap sits between a task settling and its callback being queued.
 *
 * Meant for cron, a few minutes apart. --dry-run says what a real run would
 * publish, which is also a fair way to find out whether anything is being lost
 * at all: on a healthy deployment this finds nothing.
 */
#[AsCommand(name: 'app:tasks:recover', description: 'Reencola las tareas y las notificaciones cuyo mensaje se perdió antes de llegar al broker.', help: <<<'TXT'
    Busca dos cosas y vuelve a publicar su mensaje:

      - tareas en <info>pending</info> sin tocar desde hace más que la ventana:
        su mensaje de proceso no llegó a la cola;
      - tareas <info>completed</info>, <info>failed</info> o <info>canceled</info>
        con <info>callback_url</info> cuya notificación nunca se entregó.

    Repetirlo es inofensivo: una tarea que ya se está procesando rechaza la
    reclamación, y una notificación ya entregada deja de aparecer aquí.

    En cron, cada pocos minutos:

      <info>*/5 * * * * docker compose exec -T php php bin/console app:tasks:recover --stuck-for=10m</info>
    TXT)]
final class RecoverTasksCommand extends Command
{
    /**
     * Long enough that a task published a moment ago is never mistaken for a
     * lost one: it is not stuck, it is new, and re-publishing would only have
     * two workers race for a claim one of them must lose.
     */
    private const string DEFAULT_WINDOW = '10m';

    public function __construct(
        private readonly RecoverTasks $recover,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stuck-for', null, InputOption::VALUE_REQUIRED, 'Cuánto tiempo sin tocar antes de dar un mensaje por perdido: <n>m, <n>h, <n>d o <n>w.', self::DEFAULT_WINDOW)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enumera lo que se reencolaría, sin publicar nada.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de tareas de cada tipo por ejecución.', (string) RecoverTasks::DEFAULT_LIMIT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The same window grammar as the retention job, and the same parser.
        $seconds = PruneVideosCommand::secondsIn((string) $input->getOption('stuck-for'));

        if (null === $seconds) {
            $io->error('La ventana debe ser un número seguido de m, h, d o w (por ejemplo 10m).');

            return Command::INVALID;
        }

        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('El límite debe ser un entero positivo.');

            return Command::INVALID;
        }

        $before = $this->clock->now()->minusSeconds($seconds);
        $report = $this->recover->run($before, (bool) $input->getOption('dry-run'), $limit);

        if ($report->isEmpty()) {
            $io->success(\sprintf('Nada pendiente desde antes de %s.', $before->toIso8601()));

            return Command::SUCCESS;
        }

        $io->writeln($report->requeuedTaskIds, OutputInterface::VERBOSITY_VERBOSE);
        $io->writeln($report->renotifiedTaskIds, OutputInterface::VERBOSITY_VERBOSE);
        $io->success(\sprintf(
            '%s %d tarea(s) y %d notificación(es).',
            $report->dryRun ? 'Se reencolarían' : 'Reencoladas',
            $report->requeued(),
            $report->renotified(),
        ));

        return Command::SUCCESS;
    }
}
