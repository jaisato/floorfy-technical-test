<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use Doctrine\DBAL\Connection;

/**
 * The database answers.
 *
 * A round trip, not `isConnected()`: a connection object exists long before
 * the server on the other end is willing to talk, and a pool handed a dead
 * socket says it is connected right up to the next query.
 */
final readonly class DatabaseCheck implements HealthCheck
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): CheckResult
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            return CheckResult::failed($e->getMessage());
        }

        return CheckResult::ok();
    }
}
