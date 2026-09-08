<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application\Redaction;

use App\Shared\Application\Redaction\Urls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two of the URLs this application handles are a client's and routinely carry a
 * credential: the callback endpoint, and the image a task renders from, which
 * for an object store is usually presigned. Both reach the application log and
 * the failure transport.
 */
final class UrlsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function endpoints(): iterable
    {
        yield 'userinfo, path and query go' => [
            'https://bot:s3cr3t@client.example:8443/hook?token=abcdef',
            'https://client.example:8443/…?…',
        ];
        yield 'a URL with no path at all keeps its shape' => [
            'https://client.example',
            'https://client.example',
        ];
        yield 'and a bare slash is not a path worth hiding' => [
            'https://client.example/',
            'https://client.example/',
        ];
        yield 'a presigned object-store URL keeps neither its object nor its signature' => [
            'https://bucket.s3.amazonaws.com/img/1.png?X-Amz-Signature=deadbeef&X-Amz-Expires=900',
            'https://bucket.s3.amazonaws.com/…?…',
        ];
        // The credential lives in the path as often as in the query, and this
        // feeds the public `callback_url`: a task listing handed the token out.
        // The host is made up on purpose: the shape below is the one a chat
        // service's incoming webhook has, and a real one written here - even
        // an invented one - is what a secret scanner blocks a push over.
        yield 'a webhook whose token is its path keeps neither' => [
            'https://hooks.example.com/services/T00000000/B00000000/abcdefghijklmnopqrstuvwx',
            'https://hooks.example.com/…',
        ];
        yield 'and one whose whole path is the secret' => [
            'https://client.example/s3cr3t',
            'https://client.example/…',
        ];
        yield 'unparseable is reported as such rather than echoed' => [
            'http://:::not a url',
            '(URL ilegible)',
        ];
    }

    #[DataProvider('endpoints')]
    public function testAnEndpointIsNamedWithoutItsCredentials(string $url, string $expected): void
    {
        self::assertSame($expected, Urls::endpoint($url));
    }

    /**
     * The shape a transport exception takes: a sentence with the whole request
     * URL quoted inside it.
     */
    public function testEveryUrlInsideAMessageIsRedacted(): void
    {
        $scrubbed = Urls::scrub(
            'Could not resolve host for "https://bot:s3cr3t@a.example/x?token=1", '
            .'after a redirect from https://b.example/y?key=2.',
        );

        self::assertStringNotContainsString('s3cr3t', $scrubbed);
        self::assertStringNotContainsString('token=1', $scrubbed);
        self::assertStringNotContainsString('key=2', $scrubbed);
        self::assertStringContainsString('"https://a.example/…?…"', $scrubbed);
        self::assertStringContainsString('https://b.example/…?….', $scrubbed, 'the full stop is the sentence, not the URL');
    }

    public function testTextWithoutAUrlIsLeftAlone(): void
    {
        self::assertSame('Connection timed out after 10000 ms', Urls::scrub('Connection timed out after 10000 ms'));
    }
}
