<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Query;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Query\GetVideoTaskHandler;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Tests\Support\InMemoryPartialVideoRepository;
use App\Tests\Support\InMemoryVideoTaskRepository;
use PHPUnit\Framework\TestCase;

final class GetVideoTaskHandlerTest extends TestCase
{
    private InMemoryVideoTaskRepository $tasks;
    private InMemoryPartialVideoRepository $partials;
    private DateTimeValue $now;

    protected function setUp(): void
    {
        $this->tasks = new InMemoryVideoTaskRepository();
        $this->partials = new InMemoryPartialVideoRepository();
        $this->now = DateTimeValue::fromString('2026-01-02T03:04:05+00:00');
    }

    public function testAnUnknownTaskHasNoView(): void
    {
        self::assertNull(($this->handler())(new GetVideoTaskQuery('0195c6a0-1c37-7000-8000-0000000000ff')));
    }

    /**
     * A typo in the path is a task that does not exist, not a 500: fromString()
     * would throw, Messenger would wrap it, and the controller - which knows how
     * to answer 404 - would never see it.
     */
    public function testAnIdThatIsNotAUuidIsSimplyNotFound(): void
    {
        self::assertNull(($this->handler())(new GetVideoTaskQuery('not-a-uuid')));
    }

    public function testItReportsTheTaskAndItsPartsInOrder(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($task);

        $first = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->now);
        $second = PartialVideo::create($task->id(), 'https://example.com/b.png', Transition::ZOOM_IN, 1, $this->now);
        $this->partials->saveAll([$second, $first]);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame($task->id()->value, $view->taskId);
        self::assertSame('pending', $view->status);
        self::assertSame(
            ['https://example.com/a.png', 'https://example.com/b.png'],
            array_column($view->partialVideosAsArray(), 'image_url'),
        );
    }

    /**
     * A caller polling a task needs to know which part failed and why, and be
     * able to fetch the parts that are ready.
     */
    public function testEachPartCarriesItsIdStatusUrlAndError(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($task);

        $done = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->now);
        $done->markCompleted('/videos/partial_a.mp4', $this->now);

        $broken = PartialVideo::create($task->id(), 'https://example.com/b.png', Transition::ZOOM_OUT, 1, $this->now);
        $broken->markFailed('la descarga falló', $this->now);

        $this->partials->saveAll([$done, $broken]);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame([
            [
                'id' => $done->id()->value,
                'image_url' => 'https://example.com/a.png',
                'transition' => 'pan',
                'status' => 'completed',
                'video_url' => 'http://localhost:8080/videos/partial_a.mp4',
                'error' => null,
            ],
            [
                'id' => $broken->id()->value,
                'image_url' => 'https://example.com/b.png',
                'transition' => 'zoom_out',
                'status' => 'failed',
                'video_url' => null,
                'error' => 'la descarga falló',
            ],
        ], $view->partialVideosAsArray());
    }

    public function testACompletedTaskCarriesItsFinalUrl(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $task->markProcessing($this->now);
        $task->markCompleted('http://localhost:8080/videos/final.mp4', $this->now);
        $this->tasks->save($task);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame('completed', $view->status);
        self::assertSame('http://localhost:8080/videos/final.mp4', $view->finalVideoUrl);
        self::assertNull($view->error);
    }

    public function testAFailedTaskCarriesItsReason(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $task->markFailed('No se pudieron generar 1 de 2 vídeos parciales.', $this->now);
        $this->tasks->save($task);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame('failed', $view->status);
        self::assertNull($view->finalVideoUrl);
        self::assertSame('No se pudieron generar 1 de 2 vídeos parciales.', $view->error);
    }

    private function handler(): GetVideoTaskHandler
    {
        return new GetVideoTaskHandler($this->tasks, $this->partials, 'http://localhost:8080/');
    }
}
