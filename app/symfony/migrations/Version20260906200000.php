<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `video_tasks.callback_abandoned_at`: the notification nothing will deliver.
 *
 * The recovery sweep exists for a publish lost between the commit and the
 * broker, and it recognises one by the row it left behind: a settled task that
 * asked for a callback and has none recorded as delivered. A permanent delivery
 * failure leaves exactly that row. A callback URL the guard refuses is refused
 * on every attempt, and a deployment with no `CALLBACK_SIGNING_SECRET` cannot
 * sign any notification at all, so the handler refuses both outright rather
 * than spending the transport's retries - and the sweep published the same
 * doomed message a cutoff later, and again after that, for the life of the
 * task. Each one is a fresh POST attempt against the client's endpoint and a
 * fresh entry in the failure transport.
 *
 * This column is the difference between "still owed" and "not going to happen",
 * and only the first is the sweep's business. It is cleared when a task is
 * queued again, together with the delivered mark and the sweep's attempt: the
 * URL is checked afresh on the next delivery and the secret may have been
 * configured since, so a new run is never refused for what the previous one ran
 * into.
 *
 * Existing rows are left NULL: nothing has been given up on yet, and a task
 * that is owed a notification today is owed it after this runs.
 */
final class Version20260906200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'video_tasks.callback_abandoned_at, so the recovery sweep stops republishing a notification nothing can deliver';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE video_tasks ADD callback_abandoned_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks DROP callback_abandoned_at');
    }
}
