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

    /**
     * The guard validates the URL it is handed and nothing else, so curl must
     * not be allowed to follow a redirect on its own: a permitted public URL
     * answering `302 Location: http://169.254.169.254/` would otherwise be
     * fetched unchecked. `--proto-redir` restricts the protocols a redirect may
     * use, never the addresses, so it does not cover this.
     */
    public function testRedirectsAreNotFollowed(): void
    {
        $cmd = $this->curlCommand('https://example.com/a.mp4', ['93.184.216.34']);

        self::assertContains('--max-redirs', $cmd);
        self::assertSame('0', $cmd[array_search('--max-redirs', $cmd, true) + 1]);
    }

    /**
     * assertFetchable() returns the addresses it checked so the caller connects
     * to one of them. Handing curl the hostname instead lets it resolve again,
     * and a name served with a zero TTL can answer publicly for the guard and
     * privately for the transfer.
     */
    public function testTheConnectionIsPinnedToTheValidatedAddresses(): void
    {
        $cmd = $this->curlCommand('https://example.com/a.mp4', ['93.184.216.34', '2606:2800::1']);

        self::assertContains('--resolve', $cmd);
        self::assertSame(
            'example.com:443:93.184.216.34,[2606:2800::1]',
            $cmd[array_search('--resolve', $cmd, true) + 1],
        );

        // The hostname stays in the URL so Host, SNI and certificate validation
        // are unaffected.
        self::assertContains('https://example.com/a.mp4', $cmd);
    }

    public function testAnExplicitPortIsCarriedIntoThePin(): void
    {
        $cmd = $this->curlCommand('http://example.com:8080/a.mp4', ['93.184.216.34']);

        self::assertSame(
            'example.com:8080:93.184.216.34',
            $cmd[array_search('--resolve', $cmd, true) + 1],
        );
    }

    /** A URL that already names an address has nothing to re-resolve. */
    public function testALiteralAddressIsNotPinned(): void
    {
        self::assertNotContains(
            '--resolve',
            $this->curlCommand('https://93.184.216.34/a.mp4', ['93.184.216.34']),
        );
    }

    /**
     * @param list<string> $ips
     *
     * @return list<string>
     */
    private function curlCommand(string $url, array $ips): array
    {
        $composer = new FfmpegVideoComposer(sys_get_temp_dir());

        $method = new \ReflectionMethod($composer, 'curlCommand');
        $method->setAccessible(true);

        return $method->invoke($composer, $url, $ips, sys_get_temp_dir().'/out.mp4');
    }

    private function resolve(string $url): ?string
    {
        $composer = new FfmpegVideoComposer(sys_get_temp_dir());

        $method = new \ReflectionMethod($composer, 'resolveLocalPath');
        $method->setAccessible(true);

        return $method->invoke($composer, $url);
    }
}
