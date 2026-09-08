<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * video_tasks.final_video_url holds a path, not a URL.
 */
final class Version20260906130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the path of the final video instead of a full URL, so the address is built - and signed - when it is asked for.';
    }

    public function up(Schema $schema): void
    {
        // A stored absolute URL freezes the host the API answered on the day
        // the video was rendered, and cannot carry a signature that expires.
        // Everything before /videos/ goes; rows that already hold a path are
        // left alone by the WHERE.
        $this->addSql("UPDATE video_tasks SET final_video_url = SUBSTRING(final_video_url, LOCATE('/videos/', final_video_url)) WHERE final_video_url LIKE '%://%/videos/%'");
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. This migration changes no schema, and the base
        // URL the rows used to carry is recorded nowhere, so it cannot be put
        // back. Throwing here would only break the rollback of the migrations
        // that follow it, for no gain: a path is what the application reads.
    }
}
