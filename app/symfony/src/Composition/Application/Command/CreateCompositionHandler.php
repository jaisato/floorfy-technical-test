<?php
declare(strict_types=1);

namespace App\Composition\Application\Command;

use App\Composition\Domain\Entity\CompositionJob;
use App\Composition\Domain\Port\CompositionJobRepository;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\ValueObject\UuidValue;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final class CreateCompositionHandler
{
    private const PUBLIC_DIR = '/var/www/html/public';

    public function __construct(
        private CompositionJobRepository $repo,
        private Clock $clock,
        private MessageBusInterface $commandBus,
    ) {}

    public function __invoke(CreateCompositionCommand $cmd): string
    {
        $now = $this->clock->now();

        $sources = array_map([$this, 'normalizeAnimationSource'], $cmd->animationVideoUrls);

        $job = CompositionJob::create(UuidValue::new(), $sources, $now->toDateTimeImmutable());
        $this->repo->save($job);

        $this->commandBus->dispatch(new ComposeFinalVideoCommand($job->id()->value));

        return $job->id()->value;
    }

    private function normalizeAnimationSource(string $source): string
    {
        $source = trim($source);

        if ($source === '' || str_starts_with($source, 'file://')) {
            return $source;
        }

        $path = $source;

        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $parts = parse_url($source) ?: [];
            $path = (string) ($parts['path'] ?? '');
        }

        if ($path === '') {
            return $source;
        }

        if ($path[0] !== '/') {
            $path = '/'.$path;
        }

        // Caso típico: URL pública devuelta por la API (http://localhost:8080/animations/xxx.mp4)
        if (str_starts_with($path, '/animations/')) {
            $local = self::PUBLIC_DIR.$path;

            // Protegemos un poco contra path traversal: si realpath falla, devolvemos el local "tal cual".
            $real = realpath($local);
            if ($real !== false) {
                $base = realpath(self::PUBLIC_DIR.'/animations');
                if ($base !== false && (str_starts_with($real, $base.DIRECTORY_SEPARATOR) || $real === $base)) {
                    return 'file://'.$real;
                }
            }

            return 'file://'.$local;
        }

        return $source;
    }
}
