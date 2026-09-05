<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

/**
 * Runs a unit of work atomically.
 *
 * Declared here rather than next to its Doctrine adapter so that application
 * handlers can require a transaction without depending on the ORM.
 */
interface Transaction
{
    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
