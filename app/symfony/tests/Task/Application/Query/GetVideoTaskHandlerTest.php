<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Query;

use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Query\GetVideoTaskHandler;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Task\Application\Url\VideoUrls;
use App\Task\Domain\Entity\PartialVideo;
use App\Task\Domain\Entity\VideoTask;
use App\Task\Domain\Enum\Transition;
use App\Tests\Support\FixedClock;
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
        self::assertSame($task->id()->value, $view->summary->taskId);
        self::assertSame('pending', $view->summary->status);
        self::assertSame(
            ['https://example.com/…', 'https://example.com/…'],
            array_column($view->partialVideosAsArray(), 'image_url'),
        );
    }

    /**
     * A client polling a task with twenty images wants to know how many are
     * done, not only whether the whole thing is.
     */
    public function testTheSummaryCountsThePartsAndCarriesTheTimestamps(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($task);

        $done = PartialVideo::create($task->id(), 'https://example.com/a.png', Transition::PAN, 0, $this->now);
        $done->markCompleted('/videos/partial_a.mp4', $this->now);
        $broken = PartialVideo::create($task->id(), 'https://example.com/b.png', Transition::PAN, 1, $this->now);
        $broken->markFailed('la descarga falló', $this->now);
        $waiting = PartialVideo::create($task->id(), 'https://example.com/c.png', Transition::PAN, 2, $this->now);
        $this->partials->saveAll([$done, $broken, $waiting]);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame([
            'task_id' => $task->id()->value,
            'status' => 'pending',
            'progress' => ['completed' => 1, 'failed' => 1, 'pending' => 1, 'total' => 3, 'percent' => 33],
            'final_video_url' => null,
            'error' => null,
            'callback_url' => null,
            'created_at' => '2026-01-02T03:04:05+00:00',
            'updated_at' => '2026-01-02T03:04:05+00:00',
            'pruned_at' => null,
        ], $view->summary->toArray());
        self::assertSame(
            ['task_id', 'status', 'progress', 'final_video_url', 'error', 'callback_url', 'created_at', 'updated_at', 'pruned_at', 'partial_videos'],
            array_keys($view->toArray()),
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
                'image_url' => 'https://example.com/…',
                'transition' => 'pan',
                'status' => 'completed',
                'video_url' => 'http://localhost:8080/videos/partial_a.mp4',
                'error' => null,
            ],
            [
                'id' => $broken->id()->value,
                'image_url' => 'https://example.com/…',
                'transition' => 'zoom_out',
                'status' => 'failed',
                'video_url' => null,
                'error' => 'la descarga falló',
            ],
        ], $view->partialVideosAsArray());
    }

    /**
     * The source of an image is routinely presigned, and this handed the
     * signature back to whoever reads the task. The listing exposes every id
     * and the API is open unless a deployment turns tokens on, so a caller
     * could walk the ids and collect the credential of every source anybody
     * had submitted.
     */
    public function testAPresignedImageUrlIsServedWithoutItsCredential(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $this->tasks->save($task);

        $this->partials->saveAll([
            PartialVideo::create(
                $task->id(),
                'https://bucket.s3.example.com/in/a.png?X-Amz-Signature=deadbeef&X-Amz-Expires=900',
                Transition::PAN,
                0,
                $this->now,
            ),
            PartialVideo::create($task->id(), 'https://bot:s3cr3t@origin.example.com/b.png', Transition::ZOOM_IN, 1, $this->now),
        ]);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame(
            ['https://bucket.s3.example.com/…?…', 'https://origin.example.com/…'],
            array_column($view->partialVideosAsArray(), 'image_url'),
        );
    }

    public function testACompletedTaskCarriesItsFinalUrl(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $task->markProcessing($this->now);
        $task->markCompleted('/videos/final.mp4', $this->now);
        $this->tasks->save($task);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame('completed', $view->summary->status);
        self::assertSame('http://localhost:8080/videos/final.mp4', $view->summary->finalVideoUrl);
        self::assertNull($view->summary->error);
    }

    public function testAFailedTaskCarriesItsReason(): void
    {
        $task = VideoTask::create(['images' => []], $this->now);
        $task->markFailed('No se pudieron generar 1 de 2 vídeos parciales.', $this->now);
        $this->tasks->save($task);

        $view = ($this->handler())(new GetVideoTaskQuery($task->id()->value));

        self::assertNotNull($view);
        self::assertSame('failed', $view->summary->status);
        self::assertNull($view->summary->finalVideoUrl);
        self::assertSame('No se pudieron generar 1 de 2 vídeos parciales.', $view->summary->error);
    }

    private function handler(): GetVideoTaskHandler
    {
        return new GetVideoTaskHandler($this->tasks, $this->partials, new VideoUrls(new FixedClock(), 'http://localhost:8080/', '', 3600));
    }
}
