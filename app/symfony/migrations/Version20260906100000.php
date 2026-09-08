<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the task listing.
 *
 * GET /api/tasks filters by status and by a created_at range and orders by
 * (created_at DESC, id DESC). The two single-column indexes served the worker
 * and nothing else: a filtered listing still had to sort, and an unfiltered one
 * could not break ties on id from the index. Both are replaced by composites
 * that carry the sort key, and that the single-column lookups still use as a
 * prefix.
 */
final class Version20260906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the single-column status and created_at indexes of video_tasks with composites carrying the listing sort key.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_video_tasks_status_created_at_id ON video_tasks (status, created_at, id)');
        $this->addSql('CREATE INDEX idx_video_tasks_created_at_id ON video_tasks (created_at, id)');
        $this->addSql('DROP INDEX idx_video_tasks_status ON video_tasks');
        $this->addSql('DROP INDEX idx_video_tasks_created_at ON video_tasks');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_video_tasks_status ON video_tasks (status)');
        $this->addSql('CREATE INDEX idx_video_tasks_created_at ON video_tasks (created_at)');
        $this->addSql('DROP INDEX idx_video_tasks_status_created_at_id ON video_tasks');
        $this->addSql('DROP INDEX idx_video_tasks_created_at_id ON video_tasks');
    }
}
