<?php
declare(strict_types=1);

namespace App\Task\Application\Query;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\DTO\VideoTaskView;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.query')]
final class GetVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
    ) {}

    public function __invoke(GetVideoTaskQuery $query): ?VideoTaskView
    {
        // GET /api/tasks/{id} takes the id straight from the URL. fromString()
        // throws on anything that is not a UUID, Messenger wraps that, and the
        // controller - which does know how to answer 404 - never saw it: a typo
        // in the path came back as a 500. An id that cannot name a task is a
        // task that does not exist.
        $id = UuidValue::tryFromString($query->taskId);
        if ($id === null) {
            return null;
        }

        $task = $this->tasks->get($id);
        if ($task === null) {
            return null;
        }

        $partials = $this->partials->listByTaskId($id);
        $partialViews = [];
        foreach ($partials as $partial) {
            $partialViews[] = [
                'image_url' => $partial->imageUrl(),
                'status' => $partial->status()->value,
            ];
        }

        return new VideoTaskView(
            $task->id()->value,
            $task->status()->value,
            $partialViews,
            $task->finalVideoUrl(),
        );
    }
}
