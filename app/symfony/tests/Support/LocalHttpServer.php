<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * PHP's built-in server, bound to 127.0.0.1 on an ephemeral port.
 *
 * Downloading is mostly about what a real peer does - redirects, statuses,
 * headers, a body that stops early - and mocking the HTTP client would test the
 * mock. The suite still never leaves the machine.
 */
final readonly class LocalHttpServer
{
    private function __construct(
        private Process $process,
        public string $baseUrl,
    ) {
    }

    public static function start(): self
    {
        $port = self::freePort();
        $router = __DIR__.'/http-router.php';

        $process = new Process(['php', '-S', '127.0.0.1:'.$port, '-t', __DIR__, $router]);
        $process->start();

        self::waitUntilAccepting($port, $process);

        return new self($process, 'http://127.0.0.1:'.$port);
    }

    public function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    public function stop(): void
    {
        $this->process->stop();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (false === $socket) {
            throw new \RuntimeException(\sprintf('No se pudo reservar un puerto libre: %s (%d)', $errstr, $errno));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (false === $name || false === ($colon = strrpos($name, ':'))) {
            throw new \RuntimeException('No se pudo determinar el puerto reservado.');
        }

        return (int) substr($name, $colon + 1);
    }

    private static function waitUntilAccepting(int $port, Process $process): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if (false !== $connection) {
                fclose($connection);

                return;
            }

            if (!$process->isRunning()) {
                throw new \RuntimeException('El servidor de pruebas no arrancó: '.$process->getErrorOutput());
            }

            usleep(20_000);
        }

        $process->stop();

        throw new \RuntimeException('El servidor de pruebas no aceptó conexiones a tiempo.');
    }
}
