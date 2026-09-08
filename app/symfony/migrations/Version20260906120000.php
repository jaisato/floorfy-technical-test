<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Idempotency-Key records for POST /api/tasks.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create idempotency_keys: the request fingerprint and stored response behind each Idempotency-Key, per caller.';
    }

    public function up(Schema $schema): void
    {
        // (scope, idempotency_key) as the primary key is what makes a claim
        // atomic: two requests racing with the same key both INSERT and exactly
        // one succeeds.
        //
        // Both halves compare byte for byte (utf8mb4_bin) rather than under the
        // table's utf8mb4_unicode_ci. A key is an opaque token the caller
        // chose: under a case-insensitive collation `ABC` and `abc` are one
        // key, so a client gets somebody else's stored response or a 422 that
        // makes no sense. The scope is worse - clients configured as `Acme` and
        // `acme` shared one namespace and could replay each other's answers.
        $this->addSql('CREATE TABLE idempotency_keys (scope VARCHAR(190) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, idempotency_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, fingerprint VARCHAR(64) NOT NULL, response_status SMALLINT DEFAULT NULL, response_content_type VARCHAR(255) DEFAULT NULL, response_body LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_idempotency_keys_expires_at (expires_at), PRIMARY KEY (scope, idempotency_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_keys');
    }
}
