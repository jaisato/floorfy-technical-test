<?php

declare(strict_types=1);

namespace App\Task\Application\Query;

use App\Task\Application\ReadModel\TaskPage;
use App\Task\Application\ReadModel\VideoTaskReadRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.query')]
final readonly class ListVideoTasksHandler
{
    public function __construct(private VideoTaskReadRepository $tasks)
    {
    }

    public function __invoke(ListVideoTasksQuery $query): TaskPage
    {
        return $this->tasks->list($query->listing);
    }
}
