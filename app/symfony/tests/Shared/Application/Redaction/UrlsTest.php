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
        yield 'userinfo and query go' => [
            'https://bot:s3cr3t@client.example:8443/hook?token=abcdef',
            'https://client.example:8443/hook?…',
        ];
        yield 'a URL with nothing to hide reads as it was written' => [
            'https://client.example/hook',
            'https://client.example/hook',
        ];
        yield 'a presigned object-store URL keeps its object, not its signature' => [
            'https://bucket.s3.amazonaws.com/img/1.png?X-Amz-Signature=deadbeef&X-Amz-Expires=900',
            'https://bucket.s3.amazonaws.com/img/1.png?…',
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
        self::assertStringContainsString('"https://a.example/x?…"', $scrubbed);
        self::assertStringContainsString('https://b.example/y?….', $scrubbed, 'the full stop is the sentence, not the URL');
    }

    public function testTextWithoutAUrlIsLeftAlone(): void
    {
        self::assertSame('Connection timed out after 10000 ms', Urls::scrub('Connection timed out after 10000 ms'));
    }
}
