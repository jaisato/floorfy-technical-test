<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The queue the API publishes video work to is reachable.
 *
 * Counting the messages waiting on it is the cheapest question that actually
 * opens the connection: a broker that is down, or credentials that are wrong,
 * fail here rather than when the first task is created and the client is
 * already waiting for a 201.
 */
final readonly class TransportCheck implements HealthCheck
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.async')]
        private TransportInterface $transport,
    ) {
    }

    public function name(): string
    {
        return 'transport';
    }

    public function check(): CheckResult
    {
        try {
            if (!$this->transport instanceof MessageCountAwareInterface) {
                // A transport that cannot be counted (in-memory, in the suite)
                // has nothing to be unreachable.
                return CheckResult::ok();
            }

            $this->transport->getMessageCount();
        } catch (\Throwable $e) {
            return CheckResult::failed($e->getMessage());
        }

        return CheckResult::ok();
    }
}
