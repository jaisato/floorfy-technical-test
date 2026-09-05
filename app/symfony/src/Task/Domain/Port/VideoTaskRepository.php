<?php

declare(strict_types=1);

namespace App\Task\Domain\Port;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Entity\VideoTask;

interface VideoTaskRepository
{
    public function save(VideoTask $task): void;

    public function get(UuidValue $id): ?VideoTask;

    /**
     * Takes exclusive ownership of a task for one processing attempt.
     *
     * Reading the row and then writing "processing" back is not enough: two
     * workers handed the same message (a broker redelivery while the first is
     * still running) both read "pending", both proceed, and both write over
     * each other's partials. This is a single conditional UPDATE, so exactly
     * one of them changes a row and the other is told to drop the message.
     *
     * A task whose worker died mid-attempt would otherwise stay "processing"
     * for ever and never be picked up again, so a claim older than the lease is
     * treated as abandoned and can be taken over.
     *
     * @return bool true when the caller now owns the task, false when it must
     *              leave the task alone
     */
    public function claimForProcessing(UuidValue $id, DateTimeValue $now, DateTimeValue $staleBefore): bool;

    /**
     * Hands a claimed task back so the next delivery of the message can retry it.
     *
     * @return bool true when a claim was actually released
     */
    public function release(UuidValue $id, DateTimeValue $now): bool;
}
