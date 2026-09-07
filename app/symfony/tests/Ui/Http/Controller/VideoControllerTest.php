<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Task\Application\Url\VideoUrls;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\OverridesEnvironment;
use App\Ui\Http\Response\ApiProblem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serving a rendered video, with and without signing switched on.
 *
 * The videos directory is the real one the container points at; each test
 * writes the file it asks for and removes it afterwards.
 */
final class VideoControllerTest extends ApiTestCase
{
    use OverridesEnvironment;

    private const string NAME = 'final_01a07546-b84f-7efb-9c28-5cb2aa45e9a5.mp4';
    private const string SECRET = 'a-video-signing-secret';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }

        $this->written = [];

        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testAnExistingVideoIsServed(): void
    {
        $this->writeVideo(self::NAME);

        $this->client->request('GET', '/videos/'.self::NAME);

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame('a video', $this->sentContent());
    }

    public function testAVideoThatIsNotThereIs404(): void
    {
        $this->client->request('GET', '/videos/'.self::NAME);

        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * The route admits the two names this application produces and nothing
     * else, so no request can name a path of its own.
     */
    public function testANameTheApplicationWouldNeverProduceIsNotEvenARoute(): void
    {
        foreach (['../../../etc/passwd', 'final_not-a-uuid.mp4', 'random.mp4', 'final_01a07546-b84f-7efb-9c28-5cb2aa45e9a5.mp4.txt'] as $name) {
            $this->client->request('GET', '/videos/'.$name);

            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode(), $name);
        }
    }

    public function testUnsignedVideosAreCachedHard(): void
    {
        $this->writeVideo(self::NAME);

        $this->client->request('GET', '/videos/'.self::NAME);

        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testWithASecretAnUnsignedRequestIsRefused(): void
    {
        $this->signingClient();
        $this->writeVideo(self::NAME);

        $this->client->request('GET', '/videos/'.self::NAME);

        $this->assertStatus(Response::HTTP_FORBIDDEN);
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testWithASecretTheUrlTheApiHandsOutWorks(): void
    {
        $this->signingClient();
        $this->writeVideo(self::NAME);

        $url = $this->urls()->absolute(VideoUrls::PREFIX.self::NAME);

        self::assertIsString($url);
        $this->client->request('GET', $url);

        $this->assertStatus(Response::HTTP_OK);
        self::assertSame('a video', $this->sentContent());
    }

    public function testASignedUrlIsNotCachedByShardCaches(): void
    {
        $this->signingClient();
        $this->writeVideo(self::NAME);

        $this->client->request('GET', (string) $this->urls()->absolute(VideoUrls::PREFIX.self::NAME));

        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * Nor kept in a private one past the moment the link stops working.
     *
     * Five minutes flat outlived the signature whenever the video was fetched
     * near the end of its window: the browser went on serving it from disk
     * after the signature check would have refused it, which weakens every
     * signed URL and not only the ones whose configured TTL is under five
     * minutes.
     */
    public function testASignedUrlIsNotCachedPastItsOwnExpiry(): void
    {
        // A link with a minute to live, which is less than the five minutes
        // the ceiling would otherwise hand out.
        $this->signingClient('60');
        $this->writeVideo(self::NAME);

        $this->client->request('GET', (string) $this->urls()->absolute(VideoUrls::PREFIX.self::NAME));

        $this->assertStatus(Response::HTTP_OK);

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertSame(1, preg_match('/max-age=(\d+)/', $cacheControl, $age));
        self::assertLessThanOrEqual(60, (int) $age[1], 'never longer than the link itself');
        self::assertGreaterThan(0, (int) $age[1]);
    }

    /** The signature covers the path, so it cannot be moved to another video. */
    public function testASignatureCannotBeMovedToAnotherVideo(): void
    {
        $this->signingClient();
        $other = 'final_01a07546-b84f-7efb-9c28-5cb2aa45e9a6.mp4';
        $this->writeVideo($other);

        $url = (string) $this->urls()->absolute(VideoUrls::PREFIX.self::NAME);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $this->client->request('GET', '/videos/'.$other.'?'.http_build_query($query));

        $this->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * The whole point of the feature: with signing on, the URLs the API returns
     * are the only ones that work.
     */
    public function testTheApiReturnsSignedUrls(): void
    {
        $this->signingClient();

        $this->json('POST', '/api/tasks', ['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]]);
        $taskId = $this->responseBody()['task_id'];

        self::assertIsString($taskId);
        $this->connection()->executeStatement(
            "UPDATE video_tasks SET status = 'completed', final_video_url = ? WHERE id = ?",
            [VideoUrls::PREFIX.self::NAME, $taskId],
        );

        $this->client->request('GET', '/api/tasks/'.$taskId.'/final');

        $url = $this->responseBody()['final_video_url'];

        self::assertIsString($url);
        self::assertStringContainsString('expires=', $url);
        self::assertStringContainsString('sig=', $url);
    }

    private function signingClient(?string $ttlSeconds = null): void
    {
        $this->overrideEnv('VIDEO_URL_SECRET', self::SECRET);

        if (null !== $ttlSeconds) {
            $this->overrideEnv('VIDEO_URL_TTL_SECONDS', $ttlSeconds);
        }

        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    private function urls(): VideoUrls
    {
        $urls = self::getContainer()->get(VideoUrls::class);

        self::assertInstanceOf(VideoUrls::class, $urls);

        return $urls;
    }

    private function writeVideo(string $name): void
    {
        $directory = self::getContainer()->getParameter('app.videos_dir');

        self::assertIsString($directory);

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $file = $directory.'/'.$name;
        file_put_contents($file, 'a video');
        $this->written[] = $file;
    }

    private function sentContent(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
