<?php

declare(strict_types=1);

namespace App\Ui\Console;

use App\Shared\Application\Health\ReadinessProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The same readiness checks as GET /health/ready, from the shell.
 *
 * This is what the containers' healthchecks run: the worker serves no HTTP at
 * all, and the php-fpm container would need a client to ask itself. Exit code
 * 0 when everything passes, 1 otherwise, which is all Docker reads.
 */
#[AsCommand(name: 'app:health:ready', description: 'Comprueba las dependencias de la aplicación (base de datos, cola, ffmpeg, directorios).')]
final class HealthReadyCommand extends Command
{
    public function __construct(private readonly ReadinessProbe $probe)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $readiness = $this->probe->run();
        $io = new SymfonyStyle($input, $output);

        foreach ($readiness->checks as $name => $result) {
            // The reason is in the log, where it is not part of an answer any
            // caller can read; here the operator gets it directly.
            $io->writeln(\sprintf('%-12s %s%s', $name, $result->ok ? 'ok' : 'failed', null === $result->reason ? '' : ': '.$result->reason));
        }

        return $readiness->ready ? Command::SUCCESS : Command::FAILURE;
    }
}
