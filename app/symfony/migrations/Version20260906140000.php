<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rendering options: per task, and the clip length per image.
 */
final class Version20260906140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the render options of a task (duration, fps, resolution, crossfade) and the per-image duration override.';
    }

    public function up(Schema $schema): void
    {
        // Nullable, so the rows written before this stay valid: the worker uses
        // the deployment's defaults for a task that never chose any.
        $this->addSql('ALTER TABLE video_tasks ADD render_options JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE partial_videos ADD duration_seconds DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partial_videos DROP duration_seconds');
        $this->addSql('ALTER TABLE video_tasks DROP render_options');
    }
}
