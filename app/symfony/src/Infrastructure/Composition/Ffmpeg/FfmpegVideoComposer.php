<?php
declare(strict_types=1);

namespace App\Infrastructure\Composition\Ffmpeg;

use App\Composition\Domain\Port\VideoComposer;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FfmpegVideoComposer implements VideoComposer
{
    public function __construct(
        private string $workDir,
        private readonly PublicUrlGuard $urlGuard = new PublicUrlGuard(),
    ) {}

    public function compose(array $videoUrls, string $outputBasename): string
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workDir);

        $localFiles = [];
        foreach ($videoUrls as $i => $url) {
            $dst = rtrim($this->workDir, '/')."/{$outputBasename}_part_{$i}.mp4";
            $this->download($url, $dst);
            $localFiles[] = $dst;
        }

        $listFile = rtrim($this->workDir, '/')."/{$outputBasename}_list.txt";
        $content = '';
        foreach ($localFiles as $file) {
            $content .= "file '".str_replace("'", "'\\''", $file)."'\n";
        }
        file_put_contents($listFile, $content);

        $out = rtrim($this->workDir, '/')."/{$outputBasename}_final.mp4";

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $listFile,
            '-c', 'copy',
            $out
        ]);

        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('FFmpeg falló: '.$process->getErrorOutput());
        }

        return $out;
    }

    private function download(string $url, string $dstFile): void
    {
        $fs = new Filesystem();
        $fs->mkdir(\dirname($dstFile));

        $localPath = $this->resolveLocalPath($url);
        if ($localPath !== null) {
            if (!is_file($localPath)) {
                throw new \RuntimeException(sprintf('No existe el fichero local para "%s" (resuelto a "%s")', $url, $localPath));
            }
            $fs->copy($localPath, $dstFile, true);

            return;
        }

        // Everything ProcessVideoTaskHandler passes today is a file:// path this
        // application wrote itself, so this branch is currently unreachable -
        // but the port is declared as taking "videoUrls" and this class ships a
        // full HTTP fetch, so it is one caller away from being reachable. Left
        // as it was, that fetch was a plain `curl -L` against whatever it was
        // given: no scheme restriction, no address check, and redirects followed
        // by curl itself, which is exactly the server-side request forgery
        // primitive PublicUrlGuard exists to deny. ImageDownloader - the path
        // that does take URLs from the public API - has been going through the
        // guard all along; this one never did.
        //
        // Checking it here costs one DNS resolution on a branch nothing calls,
        // and means the guard cannot be bypassed simply by reaching the composer
        // instead of the downloader.
        $ips = $this->urlGuard->assertFetchable($url);

        $proc = new Process($this->curlCommand($url, $ips, $dstFile));
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('No se pudo descargar vídeo: ' . $proc->getErrorOutput());
        }
    }

    /**
     * Builds the curl invocation for a URL the guard has just approved.
     *
     * Two things the guard cannot do on its own, both of which used to be left
     * open here:
     *
     * - **Redirects.** The guard validates the URL it is given, and nothing
     *   else. curl follows redirects by itself, so a permitted public URL
     *   answering `302 Location: http://169.254.169.254/` was fetched with no
     *   further check - the exact request forgery the guard exists to deny.
     *   `--proto-redir` does not help: it restricts which *protocols* a redirect
     *   may use, never which addresses. So redirects are not followed at all.
     *   `-L` with `--max-redirs 0` makes curl fail loudly (exit 47) instead of
     *   silently writing a 3xx body into the output file, which is what
     *   dropping `-L` would do.
     * - **DNS rebinding.** assertFetchable() returns the addresses it checked
     *   precisely so the caller connects to one of them; handing curl the
     *   hostname instead lets it resolve again, and a name served with a zero
     *   TTL can answer publicly for the guard and privately for the transfer.
     *   `--resolve` pins the connection to the validated addresses while
     *   leaving the hostname in the URL, so Host, SNI and certificate
     *   validation are untouched.
     *
     * A URL that genuinely needs redirects should go through
     * ImageDownloader, which revalidates every hop, rather than loosening this.
     *
     * @param list<string> $ips addresses the guard validated, in resolution order
     *
     * @return list<string>
     */
    private function curlCommand(string $url, array $ips, string $dstFile): array
    {
        $cmd = ['curl', '-L', '--max-redirs', '0', '--proto', '=http,https', '--proto-redir', '=http,https'];

        $host = parse_url($url, PHP_URL_HOST);

        // A URL that already names an address has nothing to re-resolve, so
        // there is nothing to pin - the guard checked that literal.
        if (is_string($host) && $ips !== [] && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443);

            // curl takes several addresses for one host:port as a comma-separated
            // list, and fails over between them the way it would across a host's
            // own A/AAAA records. IPv6 literals go in brackets.
            $addresses = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
                $ips
            );

            $cmd[] = '--resolve';
            $cmd[] = sprintf('%s:%d:%s', $host, $port, implode(',', $addresses));
        }

        array_push($cmd, '-f', '-sS', $url, '-o', $dstFile);

        return $cmd;
    }

    /**
     * Maps an input to a file on this machine, or null when it has to be fetched.
     *
     * @throws \RuntimeException when a site-relative path escapes the public directory
     */
    private function resolveLocalPath(string $url): ?string
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);

            return $path !== '' ? $path : null;
        }

        $parts = @parse_url($url);

        // A remote URL is a remote URL. Every http(s) URL used to fall through
        // to the branch below, which took its *path component* and joined it
        // onto the public directory - so "https://example.com/a.mp4" quietly
        // served /var/www/html/public/a.mp4 rather than fetching anything, and
        // "https://example.com/../../etc/passwd" resolved clean outside that
        // directory, copying whatever the process could read into a video part.
        // The HTTP branch below was therefore near-dead by accident, which is
        // also why nothing noticed it had no address check.
        if (isset($parts['scheme'])) {
            return null;
        }

        $path = $parts['path'] ?? null;

        if ($path === null) {
            return is_file($url) ? $url : null;
        }

        $publicDir = rtrim((string) (getenv('APP_PUBLIC_DIR') ?: '/var/www/html/public'), '/');
        $candidate = $publicDir.'/'.ltrim($path, '/');

        // "/videos/../../etc/passwd" is still a site-relative path, so the join
        // has to be checked rather than trusted. Compared after realpath() so
        // that symlinks out of the directory are caught too; the file existing
        // is a precondition of that check, and download() reports a missing one.
        $root = realpath($publicDir);
        $resolved = realpath($candidate);

        if ($resolved === false || $root === false) {
            return $candidate;
        }

        if ($resolved !== $root && !str_starts_with($resolved, $root.\DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('Ruta fuera del directorio público: "%s".', $url));
        }

        return $resolved;
    }
}
