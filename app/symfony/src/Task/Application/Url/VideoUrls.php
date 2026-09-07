<?php

declare(strict_types=1);

namespace App\Task\Application\Url;

use App\Shared\Application\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the path a rendered video was published at into the URL a client gets,
 * and - when a signing secret is configured - back again.
 *
 * Only the path is stored with the task. A full URL in the database is wrong
 * twice over: it freezes the host the API answered on the day the video was
 * made, and it cannot carry a signature that expires.
 *
 * With VIDEO_URL_SECRET set, every video URL carries `expires` and `sig`:
 *
 *     /videos/final_<id>.mp4?expires=1767322800&sig=<hmac>
 *
 * The signature is an HMAC-SHA256 over the path and the deadline, so a URL
 * cannot be edited into another video or a later expiry, and it stops working
 * on its own. That is what makes an API key worth switching on: without it, the
 * videos an authenticated API produces would still be readable by anyone who
 * guessed a UUID, and the URL is the one thing a <video> tag can carry - it
 * cannot send an Authorization header.
 *
 * With no secret the URLs are plain and nothing is checked, which is the
 * default and the behaviour this project had.
 */
final readonly class VideoUrls
{
    public const string PREFIX = '/videos/';

    public function __construct(
        private Clock $clock,
        #[Autowire(param: 'app.public_base_url')]
        private string $publicBaseUrl,
        #[Autowire(env: 'VIDEO_URL_SECRET')]
        private string $signingSecret,
        #[Autowire(param: 'app.video_url_ttl_seconds')]
        private int $ttlSeconds,
    ) {
    }

    public function signingEnabled(): bool
    {
        return '' !== $this->signingSecret;
    }

    /**
     * The URL for a stored path (`/videos/…`), signed when signing is on.
     * Null in, null out: a part that has not been rendered has no URL.
     */
    public function absolute(?string $path): ?string
    {
        if (null === $path) {
            return null;
        }

        $url = rtrim($this->publicBaseUrl, '/').$path;

        if (!$this->signingEnabled()) {
            return $url;
        }

        $expires = $this->clock->now()->toDateTimeImmutable()->getTimestamp() + $this->ttlSeconds;

        return $url.'?expires='.$expires.'&sig='.$this->signature($path, $expires);
    }

    /**
     * Whether this request may read this video.
     *
     * An expired or forged signature is refused; an unsigned deployment lets
     * everything through, which is what it means to have no secret.
     */
    public function mayServe(string $path, ?string $expires, ?string $signature): bool
    {
        if (!$this->signingEnabled()) {
            return true;
        }

        if (null === $expires || null === $signature || 1 !== preg_match('/^\d{1,10}$/', $expires)) {
            return false;
        }

        if ((int) $expires < $this->clock->now()->toDateTimeImmutable()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($path, (int) $expires), $signature);
    }

    /**
     * How long a link still has, from the `expires` it carries.
     *
     * Zero for a link that is unreadable or already past, so a caller that uses
     * this for a cache lifetime cannot hand out more time than the signature
     * has - which is the point: a video fetched near the end of its window and
     * cached for a flat five minutes went on being served from disk after this
     * class would have refused it.
     */
    public function secondsLeft(?string $expires): int
    {
        if (null === $expires || 1 !== preg_match('/^\d{1,10}$/', $expires)) {
            return 0;
        }

        return max(0, (int) $expires - $this->clock->now()->toDateTimeImmutable()->getTimestamp());
    }

    private function signature(string $path, int $expires): string
    {
        // The path and the deadline both inside the MAC, separated by a
        // character neither can contain: without that, moving a digit from the
        // expiry into the path would produce the same input.
        return hash_hmac('sha256', $path."\n".$expires, $this->signingSecret);
    }
}
