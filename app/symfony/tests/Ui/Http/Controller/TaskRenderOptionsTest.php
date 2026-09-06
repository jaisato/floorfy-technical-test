<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Shared\Domain\ValueObject\UuidValue;
use App\Task\Domain\Port\VideoTaskRepository;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render options over HTTP: what is accepted, what is refused, and what ends
 * up stored with the task.
 */
final class TaskRenderOptionsTest extends ApiTestCase
{
    public function testATaskWithoutOptionsGetsTheDeploymentsDefaults(): void
    {
        $options = $this->optionsOf($this->create(['images' => [self::image()]]));

        self::assertEquals($this->defaults(), $options);
    }

    public function testTheOptionsARequestChoosesAreStoredWithTheTask(): void
    {
        $taskId = $this->create(['images' => [self::image()], 'options' => [
            'duration' => 6,
            'fps' => 24,
            'resolution' => '1920x1080',
            'crossfade' => 1.5,
        ]]);

        $options = $this->optionsOf($taskId);

        self::assertSame(6.0, $options->duration);
        self::assertSame(24, $options->fps);
        self::assertSame('1920x1080', $options->resolution);
        self::assertSame(1.5, $options->crossfade);
    }

    /** Anything the request leaves out keeps the default. */
    public function testAPartialSetOfOptionsKeepsTheRest(): void
    {
        $options = $this->optionsOf($this->create(['images' => [self::image()], 'options' => ['crossfade' => 0.5]]));

        self::assertSame(0.5, $options->crossfade);
        self::assertSame($this->defaults()->fps, $options->fps);
        self::assertSame($this->defaults()->resolution, $options->resolution);
    }

    /**
     * Duration is the one option that may differ per image: frame rate and
     * size have to match across the parts for them to be joined at all.
     */
    public function testAnImageMayAskForItsOwnDuration(): void
    {
        $taskId = $this->create(['images' => [
            ['url' => 'https://example.com/a.png', 'transition' => 'pan', 'duration' => 8],
            ['url' => 'https://example.com/b.png', 'transition' => 'pan'],
        ]]);

        self::assertSame(
            [8.0, null],
            $this->connection()->fetchFirstColumn(
                'SELECT duration_seconds FROM partial_videos WHERE task_id = ? ORDER BY position',
                [$taskId],
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedBodies(): iterable
    {
        yield 'duration too short' => [['images' => [self::image()], 'options' => ['duration' => 0.5]]];
        yield 'duration too long' => [['images' => [self::image()], 'options' => ['duration' => 20]]];
        yield 'unknown frame rate' => [['images' => [self::image()], 'options' => ['fps' => 60]]];
        yield 'frame rate as text' => [['images' => [self::image()], 'options' => ['fps' => '30']]];
        yield 'unknown resolution' => [['images' => [self::image()], 'options' => ['resolution' => '640x480']]];
        yield 'negative crossfade' => [['images' => [self::image()], 'options' => ['crossfade' => -1]]];
        yield 'crossfade too long' => [['images' => [self::image()], 'options' => ['crossfade' => 5]]];
        yield 'unknown option' => [['images' => [self::image()], 'options' => ['codec' => 'av1']]];
        yield 'options not an object' => [['images' => [self::image()], 'options' => 'fast']];
        yield 'per-image duration too long' => [['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan', 'duration' => 99]]]];
        yield 'per-image duration as text' => [['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan', 'duration' => 'long']]]];
        yield 'per-image unknown field' => [['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan', 'fps' => 24]]]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('refusedBodies')]
    public function testOptionsThatCannotProduceAVideoAreRefused(array $body): void
    {
        $this->json('POST', '/api/tasks', $body);

        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM video_tasks'));
    }

    /** @return array{url: string, transition: string} */
    private static function image(): array
    {
        return ['url' => 'https://example.com/a.png', 'transition' => 'pan'];
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): string
    {
        $this->json('POST', '/api/tasks', $body);

        $this->assertStatus(Response::HTTP_CREATED);

        $taskId = $this->responseBody()['task_id'];

        self::assertIsString($taskId);

        return $taskId;
    }

    private function optionsOf(string $taskId): RenderOptions
    {
        $tasks = self::getContainer()->get(VideoTaskRepository::class);

        self::assertInstanceOf(VideoTaskRepository::class, $tasks);

        $options = $tasks->get(UuidValue::fromString($taskId))?->renderOptions();

        self::assertInstanceOf(RenderOptions::class, $options, 'una tarea creada hoy guarda sus opciones');

        return $options;
    }

    private function defaults(): RenderOptions
    {
        $defaults = self::getContainer()->get(RenderOptions::class);

        self::assertInstanceOf(RenderOptions::class, $defaults);

        return $defaults;
    }
}
