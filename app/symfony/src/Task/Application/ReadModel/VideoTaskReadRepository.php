<?php

declare(strict_types=1);

namespace App\Task\Application\ReadModel;

/**
 * The read side of tasks: listings built for the API, not entities.
 *
 * Kept apart from VideoTaskRepository on purpose. A listing wants a page of
 * rows with a part count each, which is one query; loading every task as an
 * entity and its parts as more entities to count them would be a query per
 * task, and would drag the write model into shaping responses.
 */
interface VideoTaskReadRepository
{
    public function list(TaskListing $listing): TaskPage;
}
