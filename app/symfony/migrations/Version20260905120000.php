<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Referential integrity, deterministic part ordering and URL columns wide
 * enough for the URLs the API accepts.
 */
final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial_videos.position, the task foreign key and the lookup indexes; widen the URL columns.';
    }

    public function up(Schema $schema): void
    {
        // image_url held user input in VARCHAR(255) while the API accepted
        // longer URLs, so a legal request failed at INSERT time as a 500.
        $this->addSql('ALTER TABLE partial_videos CHANGE image_url image_url VARCHAR(2048) NOT NULL');
        $this->addSql('ALTER TABLE partial_videos CHANGE video_path video_path VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE video_tasks CHANGE final_video_url final_video_url VARCHAR(2048) DEFAULT NULL');

        // Parts were concatenated in created_at order, and every part of a task
        // is created in the same request: with one-second resolution the order
        // was whatever the database happened to return.
        $this->addSql('ALTER TABLE partial_videos ADD position INT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE partial_videos p JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY task_id ORDER BY created_at, id) - 1 AS pos FROM partial_videos) o ON o.id = p.id SET p.position = o.pos');
        $this->addSql('ALTER TABLE partial_videos ALTER COLUMN position DROP DEFAULT');

        // Nothing stopped a part pointing at a task that does not exist, and
        // deleting a task left its parts behind for good.
        $this->addSql('DELETE FROM partial_videos WHERE task_id NOT IN (SELECT id FROM video_tasks)');
        $this->addSql('DROP INDEX idx_partial_videos_task_id ON partial_videos');
        $this->addSql('CREATE INDEX idx_partial_videos_task_position ON partial_videos (task_id, position)');
        $this->addSql('ALTER TABLE partial_videos ADD CONSTRAINT fk_partial_videos_task FOREIGN KEY (task_id) REFERENCES video_tasks (id) ON DELETE CASCADE');

        // The worker claims by status; the API lists newest first.
        $this->addSql('CREATE INDEX idx_video_tasks_status ON video_tasks (status)');
        $this->addSql('CREATE INDEX idx_video_tasks_created_at ON video_tasks (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_video_tasks_created_at ON video_tasks');
        $this->addSql('DROP INDEX idx_video_tasks_status ON video_tasks');

        $this->addSql('ALTER TABLE partial_videos DROP FOREIGN KEY fk_partial_videos_task');
        $this->addSql('DROP INDEX idx_partial_videos_task_position ON partial_videos');
        $this->addSql('CREATE INDEX idx_partial_videos_task_id ON partial_videos (task_id)');
        $this->addSql('ALTER TABLE partial_videos DROP position');

        $this->addSql('ALTER TABLE video_tasks CHANGE final_video_url final_video_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE partial_videos CHANGE video_path video_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE partial_videos CHANGE image_url image_url VARCHAR(255) NOT NULL');
    }
}
