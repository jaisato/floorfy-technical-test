<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * One of the queues this deployment depends on is reachable.
 *
 * Counting the messages waiting on it is the cheapest question that actually
 * opens the connection: a broker that is down, or credentials that are wrong,
 * fail here rather than when the first task is created and the client is
 * already waiting for a 201.
 *
 * Registered once per transport, because they are configured separately.
 * Probing only `async` left the callbacks queue - its own DSN, its own vhost,
 * its own credentials - unchecked: with a bad one, /health/ready reported
 * success and nginx served traffic while no webhook could be published or
 * consumed.
 */
final readonly class TransportCheck implements HealthCheck
{
    public function __construct(
        private string $checkName,
        private TransportInterface $transport,
    ) {
    }

    public function name(): string
    {
        return $this->checkName;
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
