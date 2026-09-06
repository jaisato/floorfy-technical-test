<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `video_tasks.callback_notified_at`, and the two indexes the recovery sweep
 * reads.
 *
 * The queue is a broker, so publishing a message cannot be part of the
 * transaction that writes the row it is about: a process that dies between the
 * commit and the publish leaves a task nobody will process, or a settled task
 * whose callback nobody will deliver. Recording when a callback was actually
 * delivered is what lets `app:tasks:recover` tell the second case apart from a
 * task that simply has not been notified yet.
 */
final class Version20260906160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'video_tasks.callback_notified_at and the indexes the recovery sweep uses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE video_tasks ADD callback_notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_video_tasks_status_updated_at ON video_tasks (status, updated_at)');
        $this->addSql('CREATE INDEX idx_video_tasks_callback_notified_at_updated_at ON video_tasks (callback_notified_at, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_video_tasks_callback_notified_at_updated_at ON video_tasks');
        $this->addSql('DROP INDEX idx_video_tasks_status_updated_at ON video_tasks');
        $this->addSql('ALTER TABLE video_tasks DROP callback_notified_at');
    }
}
