<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `video_tasks.run_generation`: which attempt of a task a writer is talking
 * about.
 *
 * Every conditional write in the repository so far identified an attempt by
 * the task's status, and a status is reusable. Two things went wrong with
 * that.
 *
 * A worker inside ffmpeg holds nothing but "the row says processing". Cancel
 * the task and retry it, and the replacement worker puts the row back to
 * processing: the old worker's next renewal, its release and its completion
 * all match again, so it carries on believing it owns the task and can reset
 * or finish somebody else's run while both write the same staging paths.
 *
 * The callback marks had the same shape with `updated_at` bolted on, and that
 * column is a MySQL `DATETIME`: one second. Cancel a task, retry it and cancel
 * it again before a worker claims it, and both runs settle inside that second
 * with the same status - so the first notification's mark was accepted for the
 * second, and when the second was lost the recovery sweep saw a delivered mark
 * and never offered it again.
 *
 * The counter answers both. It is bumped by every write that starts a run
 * (the claim) or ends one (complete, fail, cancel, release), so a number
 * names one attempt of one task and is never seen twice. A worker carries the
 * number its claim produced and every later write of its own is conditional on
 * it; a notification carries the number of the transition that produced it.
 *
 * Existing rows start at 1, which is what a task created after this gets too:
 * anything holding an older claim is holding no number at all, and its writes
 * were already refused by the status conditions that stay in place.
 */
final class Version20260907100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'video_tasks.run_generation, so a claim and a callback name one attempt rather than a reusable status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks ADD run_generation INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_tasks DROP run_generation');
    }
}
