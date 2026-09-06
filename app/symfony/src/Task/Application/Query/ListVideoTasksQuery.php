<?php

declare(strict_types=1);

namespace App\Task\Application\Query;

use App\Shared\Domain\Bus\Query;
use App\Task\Application\ReadModel\TaskListing;

final readonly class ListVideoTasksQuery implements Query
{
    /**
     * @param TaskListing $listing the filters and page, already validated by
     *                             the UI layer
     */
    public function __construct(public TaskListing $listing)
    {
    }
}
