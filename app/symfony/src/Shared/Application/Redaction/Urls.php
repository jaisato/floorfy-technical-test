<?php

declare(strict_types=1);

namespace App\Shared\Application\Redaction;

/**
 * URLs as they may be written down.
 *
 * Two of the URLs this application handles are a client's and routinely carry
 * a credential: the callback endpoint a task is notified on, and the image a
 * task renders from, which for an object store is usually presigned. Both end
 * up in the application log and, once the retries are spent, in the failure
 * transport, where whoever operates either can read them.
 *
 * So a URL is named by where it points - scheme, host, port - and never by its
 * userinfo, its path or its query. That applies to what a lower layer said as
 * well: Symfony's transport exceptions quote the whole request URL back
 * (`... for "https://bot:s3cr3t@host/path?token=…"`), which is how a message
 * that nobody wrote deliberately puts a secret in a log line.
 */
final class Urls
{
    /**
     * Redacts every URL inside a piece of text that was not written here.
     *
     * The trailing punctuation of the sentence around a URL is not part of it,
     * and leaving it inside would only mean it is dropped with the query.
     */
    public static function scrub(string $text): string
    {
        return preg_replace_callback(
            '~[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i',
            static function (array $match): string {
                $url = rtrim($match[0], '.,;:!?)]}');

                return self::endpoint($url).substr($match[0], \strlen($url));
            },
            $text,
        ) ?? $text;
    }

    /**
     * A URL without anything that could be a credential: scheme, host and port,
     * which is where it points, plus a mark for each part that was dropped.
     *
     * The query goes whole rather than by parameter name: `?token=`, `?key=`,
     * `?sig=`, `?X-Amz-Signature=` and whatever the next service calls it are
     * not a list this can keep up with, and a query that is only `?taskId=` is
     * already in the log line beside this one.
     *
     * The path goes for the same reason, which took one more round to see. A
     * webhook's credential lives there as often as in the query - Slack's is
     * `/services/T…/B…/<token>`, and `/hooks/<secret>` is the usual shape
     * elsewhere - and this feeds the `callback_url` and `image_url` of the
     * public task representation, so anyone who could list tasks could read
     * them. Nor is there a rule that keeps the safe part: the secret is the
     * whole path in one service, the last segment in another and the first in a
     * third, and picking by shape is the same losing game as picking by
     * parameter name. What identifies the endpoint for an operator is the host
     * it points at; which task it belongs to is on the same line already.
     *
     * A URL that will not parse is reported as such instead of echoed.
     */
    public static function endpoint(string $url): string
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['host'])) {
            return '(URL ilegible)';
        }

        $path = $parts['path'] ?? '';

        return \sprintf(
            '%s%s%s%s%s',
            isset($parts['scheme']) ? $parts['scheme'].'://' : '',
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            '' === $path || '/' === $path ? $path : '/…',
            isset($parts['query']) ? '?…' : '',
        );
    }
}
