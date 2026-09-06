<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `video_tasks.callback_attempted_at`: what the recovery sweep already tried.
 *
 * The sweep republishes the notification of a settled task whose callback was
 * never delivered. "Never delivered" was read off `callback_notified_at`
 * alone, which is stamped only once the POST succeeds - so a delivery the
 * transport is still retrying looks exactly like one that was lost, and every
 * sweep in the meantime published another. The client got the same POST as
 * many times as the sweep ran.
 *
 * Recording the attempt lets the sweep see its own republish and wait out the
 * same cutoff before offering that task again, without ever claiming a
 * notification as delivered when it was not. Existing rows are left NULL: a
 * task that was owed a notification before this migration is owed it still,
 * and the first sweep after it will take it.
 */
final class Version20260906170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'video_tasks.callback_attempted_at, so the recovery sweep does not republish what it just published';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE video_tasks ADD callback_attempted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks DROP callback_attempted_at');
    }
}
