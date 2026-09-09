<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `idempotency_keys.claim_token`: which attempt of a request holds the claim.
 *
 * A claim that stood unanswered past the store's grace is taken over by the
 * client's retry. The request that abandoned it is usually dead, but not
 * always: max_execution_time counts CPU time, not a wait, so one blocked in a
 * resolver or a database call is still there long after nginx gave up on it,
 * and comes back to write. Without a token, its complete() matched the row by
 * (scope, key) alone and stored its answer over the retry's - to be replayed
 * to that client as the answer to a request it never made - and its release()
 * deleted the retry's claim while the retry was working under it. The token
 * names the attempt; both writes carry it and match nothing else.
 *
 * Empty for the rows in flight at this moment: their requests hand back a
 * token nothing matches, so their answers are not stored and their keys are
 * not released - the state they would be in had the deploy interrupted them,
 * and one a retry recovers from once the grace has passed.
 */
final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'idempotency_keys.claim_token, so the writes that end a request match its own claim and not the retry that took it over';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE idempotency_keys ADD claim_token VARCHAR(32) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idempotency_keys DROP claim_token');
    }
}
