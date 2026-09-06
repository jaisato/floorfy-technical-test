<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a task's videos were deleted by the retention job.
 */
final class Version20260906150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a task had its videos pruned, and index the settled tasks the job scans.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks ADD pruned_at DATETIME DEFAULT NULL');
        // The retention job looks for settled tasks last touched before a
        // cutoff and not yet pruned; without this it reads the whole table
        // every run, and the table only grows.
        $this->addSql('CREATE INDEX idx_video_tasks_pruned_at_updated_at ON video_tasks (pruned_at, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_video_tasks_pruned_at_updated_at ON video_tasks');
        $this->addSql('ALTER TABLE video_tasks DROP pruned_at');
    }
}
