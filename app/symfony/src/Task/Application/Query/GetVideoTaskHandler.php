<?php

declare(strict_types=1);

namespace App\Task\Application\Query;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Application\DTO\PartialVideoView;
use App\Task\Application\DTO\VideoTaskSummaryView;
use App\Task\Application\DTO\VideoTaskView;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Port\PartialVideoRepository;
use App\Task\Domain\Port\VideoTaskRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'messenger.bus.query')]
final readonly class GetVideoTaskHandler
{
    public function __construct(
        private VideoTaskRepository $tasks,
        private PartialVideoRepository $partials,
        private VideoUrls $urls,
    ) {
    }

    public function __invoke(GetVideoTaskQuery $query): ?VideoTaskView
    {
        // GET /api/tasks/{id} takes the id straight from the URL. fromString()
        // throws on anything that is not a UUID, Messenger wraps that, and the
        // controller - which does know how to answer 404 - never saw it: a typo
        // in the path came back as a 500. An id that cannot name a task is a
        // task that does not exist.
        $id = UuidValue::tryFromString($query->taskId);

        if (null === $id) {
            return null;
        }

        $task = $this->tasks->get($id);

        if (null === $task) {
            return null;
        }

        $partials = $this->partials->listByTaskId($id);

        return new VideoTaskView(
            VideoTaskSummaryView::fromTask($task, $partials, $this->urls->absolute($task->finalVideoUrl())),
            array_map($this->toView(...), $partials),
        );
    }

    private function toView(PartialVideo $partial): PartialVideoView
    {
        return new PartialVideoView(
            $partial->id()->value,
            $partial->imageUrl(),
            $partial->transition()->value,
            $partial->status()->value,
            $this->urls->absolute($partial->videoPath()),
            $partial->errorMessage(),
        );
    }
}
