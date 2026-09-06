<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The optional URL a task's outcome is POSTed to.
 */
final class Version20260906110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video_tasks.callback_url, the webhook a settled task notifies.';
    }

    public function up(Schema $schema): void
    {
        // Same width as image_url: one validator bounds both.
        $this->addSql('ALTER TABLE video_tasks ADD callback_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks DROP callback_url');
    }
}
