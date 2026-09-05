<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Media;

use App\Task\Infrastructure\Media\BlockedUrl;
use App\Task\Infrastructure\Media\ImageDownloader;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Tests\Support\LocalHttpServer;
use App\Tests\Support\LoopbackTargetPolicy;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Exercised against a real server on 127.0.0.1, started by the test: redirects,
 * statuses and headers are the behaviour under test, and a mocked client would
 * only prove the mock.
 */
final class ImageDownloaderTest extends TestCase
{
    private static ?LocalHttpServer $server = null;
    private TempDirectory $work;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->work = new TempDirectory('floorfy-download');
    }

    protected function tearDown(): void
    {
        $this->work->remove();
    }

    public function testDownloadsAnImageIntoTheWorkDirectory(): void
    {
        $file = $this->downloader()->fetch(self::server()->url('/image.png'), 'task/partial');

        self::assertFileExists($file);
        self::assertStringStartsWith($this->work->path.'/images/', $file);
        self::assertSame('image/png', mime_content_type($file));
    }

    /**
     * The guard checks the URL it is handed and nothing else. Letting the HTTP
     * client follow redirects would mean a permitted public URL could send the
     * download to cloud instance metadata, unchecked - which is the whole point
     * of having a guard.
     */
    public function testARedirectToAPrivateAddressIsRefused(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('/169\.254\.169\.254/');

        $this->downloader()->fetch(self::server()->url('/redirect/to-private'), 'task/partial');
    }

    /** @return iterable<string, array{string}> */
    public static function redirectsThatReachTheImage(): iterable
    {
        yield 'absolute URL' => ['/redirect/absolute'];
        yield 'relative to the current directory' => ['/redirect/relative'];
        yield 'rooted at the host' => ['/redirect/root-relative'];
        yield 'walking up with ..' => ['/nested/redirect/parent'];
        yield 'protocol relative' => ['/redirect/protocol-relative'];
        yield 'carrying a fragment' => ['/redirect/with-fragment'];
        yield 'query only' => ['/redirect/query-only?x=1'];
    }

    #[DataProvider('redirectsThatReachTheImage')]
    public function testEveryShapeOfRedirectIsResolved(string $path): void
    {
        $file = $this->downloader()->fetch(self::server()->url($path), 'task/partial');

        self::assertSame('image/png', mime_content_type($file));
    }

    public function testARedirectLoopStopsAtTheHopLimit(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('/Demasiadas redirecciones/');

        $this->downloader()->fetch(self::server()->url('/redirect/loop'), 'task/partial');
    }

    public function testARedirectWithoutALocationIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sin cabecera Location/');

        $this->downloader()->fetch(self::server()->url('/redirect/no-location'), 'task/partial');
    }

    public function testAServerErrorIsReportedWithItsStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $this->downloader()->fetch(self::server()->url('/server-error'), 'task/partial');
    }

    /**
     * A 2xx with nothing behind it would otherwise leave a zero-byte file that
     * ffmpeg fails on later, for no obvious reason.
     */
    public function testAnEmptyBodyIsRejectedAndLeavesNoFile(): void
    {
        try {
            $this->downloader()->fetch(self::server()->url('/empty'), 'task/partial');
            self::fail('an empty response should not count as a downloaded image');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no contenía imagen', $e->getMessage());
        }

        self::assertSame([], glob($this->work->path.'/images/task/*') ?: []);
    }

    public function testAResponseThatDoesNotClaimToBeAnImageIsRefused(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('#text/html#');

        $this->downloader()->fetch(self::server()->url('/not-an-image'), 'task/partial');
    }

    /**
     * The bytes decide, not the header: what is written here is what ffmpeg is
     * asked to read.
     */
    public function testABodyThatIsNotAnImageIsRefusedEvenWhenTheHeaderClaimsOtherwise(): void
    {
        try {
            $this->downloader()->fetch(self::server()->url('/image-typed-but-html-body'), 'task/partial');
            self::fail('an HTML body served as image/png should not be accepted');
        } catch (BlockedUrl $e) {
            self::assertStringContainsString('no es una imagen', $e->getMessage());
        }

        self::assertSame([], glob($this->work->path.'/images/task/*') ?: []);
    }

    /**
     * application/octet-stream is what an object store serves when nobody set a
     * type on the upload; rejecting it outright would refuse a large share of
     * working image URLs, so the body is what decides.
     */
    public function testAGenericBinaryTypeIsAcceptedWhenTheBodyIsAnImage(): void
    {
        $file = $this->downloader()->fetch(self::server()->url('/image-octet-stream'), 'task/partial');

        self::assertSame('image/png', mime_content_type($file));
    }

    public function testAGenericBinaryTypeIsStillRefusedWhenTheBodyIsNotAnImage(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('/no es una imagen/');

        $this->downloader()->fetch(self::server()->url('/octet-stream-not-an-image'), 'task/partial');
    }

    public function testAnOversizedContentLengthIsRefusedBeforeTheBodyIsRead(): void
    {
        $this->expectException(BlockedUrl::class);
        $this->expectExceptionMessageMatches('/supera el tamaño máximo/');

        $this->downloader()->fetch(self::server()->url('/declared-too-large'), 'task/partial');
    }

    /** Content-Length is a claim; the streaming cap is the one that holds. */
    public function testABodyLongerThanTheCapIsAbandonedAndTheFileRemoved(): void
    {
        try {
            $this->downloader(maxBytes: 1024)->fetch(self::server()->url('/oversized'), 'task/partial');
            self::fail('a body over the cap should not be accepted');
        } catch (BlockedUrl $e) {
            self::assertStringContainsString('supera el tamaño máximo', $e->getMessage());
        }

        self::assertSame([], glob($this->work->path.'/images/task/*') ?: []);
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatEscapeTheWorkDirectory(): iterable
    {
        yield 'parent traversal' => ['../../etc/passwd'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'windows separators' => ['..\\..\\etc\\passwd'];
    }

    #[DataProvider('namesThatEscapeTheWorkDirectory')]
    public function testTheDestinationNeverLeavesTheWorkDirectory(string $name): void
    {
        $file = $this->downloader()->fetch(self::server()->url('/image.png'), $name);

        self::assertStringStartsWith($this->work->path.'/images/', $file);
        self::assertStringNotContainsString('..', $file);
    }

    public function testANameThatSanitisesToNothingIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nombre de fichero de imagen inválido/');

        $this->downloader()->fetch(self::server()->url('/image.png'), '../..');
    }

    /**
     * Nothing weakens the guard for production code: the loopback exception
     * lives in the test double, and the default policy still refuses 127.0.0.1.
     */
    public function testTheProductionPolicyStillRefusesTheTestServer(): void
    {
        $downloader = new ImageDownloader($this->work->path, HttpClient::create(), new PublicUrlGuard());

        $this->expectException(BlockedUrl::class);

        $downloader->fetch(self::server()->url('/image.png'), 'task/partial');
    }

    private function downloader(int $maxBytes = ImageDownloader::DEFAULT_MAX_BYTES): ImageDownloader
    {
        return new ImageDownloader(
            $this->work->path,
            HttpClient::create(),
            new PublicUrlGuard(new LoopbackTargetPolicy()),
            $maxBytes,
        );
    }

    private static function server(): LocalHttpServer
    {
        return self::$server ?? throw new \LogicException('the test server was not started');
    }
}
