<?php
declare(strict_types=1);

namespace App\Task\Application\Command;

use App\Shared\Domain\Bus\Command;

/**
 * @param list<array{url:string, transition:string}> $images
 * @param array<string,mixed> $payload
 */
final readonly class CreateVideoTaskCommand implements Command
{
    /**
     * @param list<array{url:string, transition:string}> $images
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public array $images,
        public array $payload,
    ) {}
}
