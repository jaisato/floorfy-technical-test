<?php
declare(strict_types=1);

namespace App\Tests\Infrastructure\Composition\Ffmpeg;

use App\Infrastructure\Composition\Ffmpeg\FfmpegVideoComposer;
use PHPUnit\Framework\TestCase;

/**
 * How an input is classified decides which of two very different things
 * happens to it, so the classification is worth pinning on its own.
 *
 * resolveLocalPath() used to take the path component of *any* URL and join it
 * onto the public directory. An http(s) URL was therefore never fetched - it
 * was silently served from disk - and one containing "../" resolved outside
 * that directory entirely.
 */
final class FfmpegVideoComposerTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir().'/floorfy-public-'.bin2hex(random_bytes(6));
        mkdir($this->publicDir.'/videos', 0777, true);
        file_put_contents($this->publicDir.'/videos/ok.mp4', 'video');

        putenv('APP_PUBLIC_DIR='.$this->publicDir);
    }

    protected function tearDown(): void
    {
        putenv('APP_PUBLIC_DIR');

        @unlink($this->publicDir.'/videos/ok.mp4');
        @rmdir($this->publicDir.'/videos');
        @rmdir($this->publicDir);
    }

    public function testSiteRelativePathResolvesInsideThePublicDirectory(): void
    {
        self::assertSame(
            realpath($this->publicDir.'/videos/ok.mp4'),
            $this->resolve('/videos/ok.mp4'),
        );
    }

    public function testFileUrlIsTakenAsALocalPath(): void
    {
        $path = $this->publicDir.'/videos/ok.mp4';

        self::assertSame($path, $this->resolve('file://'.$path));
    }

    /**
     * The one that mattered: an http(s) URL is something to fetch, not a path
     * on this disk. Returning null is what sends it to the download branch -
     * which now goes through PublicUrlGuard.
     */
    public function testHttpUrlIsNotTreatedAsALocalFile(): void
    {
        self::assertNull($this->resolve('https://example.com/videos/ok.mp4'));
        self::assertNull($this->resolve('http://example.com/videos/ok.mp4'));
    }

    public function testSiteRelativeTraversalIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/fuera del directorio p/');

        $this->resolve('/videos/../../../../etc/passwd');
    }

    private function resolve(string $url): ?string
    {
        $composer = new FfmpegVideoComposer(sys_get_temp_dir());

        $method = new \ReflectionMethod($composer, 'resolveLocalPath');
        $method->setAccessible(true);

        return $method->invoke($composer, $url);
    }
}
