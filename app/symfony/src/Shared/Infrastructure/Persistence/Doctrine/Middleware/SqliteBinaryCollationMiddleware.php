<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Teaches SQLite the MySQL collation the mapping names.
 *
 * `idempotency_keys.scope` and `.idempotency_key` are declared `utf8mb4_bin`,
 * because both are opaque tokens compared byte for byte: under the table's
 * utf8mb4_unicode_ci, `ABC` and `abc` were one key, and clients configured as
 * `Acme` and `acme` shared one scope and could replay each other's stored
 * responses. The mapping has to say so - Doctrine puts the connection's default
 * collation on every column of the mapping side, so a column left undeclared
 * reads as utf8mb4_unicode_ci and `doctrine:schema:validate` reports the
 * database as out of sync.
 *
 * SQLite, which the test profile uses, then meets a collation name it has never
 * heard of and refuses the CREATE TABLE. It has the behaviour already - its
 * default for TEXT is BINARY - it only lacks the name, so this registers one
 * that does exactly that on every SQLite connection. Every other driver passes
 * through untouched, which is why it is safe to leave registered everywhere.
 */
final class SqliteBinaryCollationMiddleware implements Middleware
{
    public const COLLATION = 'utf8mb4_bin';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);
                $native = $connection->getNativeConnection();

                // Only PDO SQLite has sqliteCreateCollation(); everything else
                // either knows the name already or is not our business.
                if ($native instanceof \PDO && 'sqlite' === $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                    $native->sqliteCreateCollation(
                        SqliteBinaryCollationMiddleware::COLLATION,
                        static fn (string $left, string $right): int => strcmp($left, $right),
                    );
                }

                return $connection;
            }
        };
    }
}
