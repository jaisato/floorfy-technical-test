<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Idempotency;

use App\Ui\Http\Idempotency\RequestFingerprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What counts as "the same request" when an Idempotency-Key is repeated.
 */
final class RequestFingerprintTest extends TestCase
{
    public function testTheSameRequestFingerprintsTheSame(): void
    {
        $body = '{"images":[{"url":"https://example.com/a.jpg","transition":"pan"}]}';

        self::assertSame(
            RequestFingerprint::of(self::post('/api/tasks', $body)),
            RequestFingerprint::of(self::post('/api/tasks', $body)),
        );
    }

    /**
     * A client that serialises the same object twice can emit its keys in a
     * different order; that is the same request, and a retry of it must replay
     * rather than be refused.
     */
    public function testKeyOrderDoesNotChangeTheFingerprint(): void
    {
        self::assertSame(
            RequestFingerprint::of(self::post('/api/tasks', '{"images":[{"url":"u","transition":"pan"}]}')),
            RequestFingerprint::of(self::post('/api/tasks', '{"images":[{"transition":"pan","url":"u"}]}')),
        );
    }

    /** Order within a list is meaning, not formatting: these are two videos. */
    public function testListOrderDoesChangeTheFingerprint(): void
    {
        self::assertNotSame(
            RequestFingerprint::of(self::post('/api/tasks', '{"images":["a","b"]}')),
            RequestFingerprint::of(self::post('/api/tasks', '{"images":["b","a"]}')),
        );
    }

    public function testADifferentValueChangesTheFingerprint(): void
    {
        self::assertNotSame(
            RequestFingerprint::of(self::post('/api/tasks', '{"images":[{"url":"a"}]}')),
            RequestFingerprint::of(self::post('/api/tasks', '{"images":[{"url":"b"}]}')),
        );
    }

    public function testADifferentPathChangesTheFingerprint(): void
    {
        self::assertNotSame(
            RequestFingerprint::of(self::post('/api/tasks', '{}')),
            RequestFingerprint::of(self::post('/api/other', '{}')),
        );
    }

    public function testADifferentMethodChangesTheFingerprint(): void
    {
        $get = Request::create('/api/tasks', 'GET', content: '{}');

        self::assertNotSame(
            RequestFingerprint::of(self::post('/api/tasks', '{}')),
            RequestFingerprint::of($get),
        );
    }

    /**
     * A body that is not JSON cannot be canonicalised, so it is compared as it
     * arrived - byte for byte.
     */
    public function testABodyThatIsNotJsonIsComparedLiterally(): void
    {
        self::assertSame(
            RequestFingerprint::of(self::post('/api/tasks', 'not json')),
            RequestFingerprint::of(self::post('/api/tasks', 'not json')),
        );

        self::assertNotSame(
            RequestFingerprint::of(self::post('/api/tasks', 'not json')),
            RequestFingerprint::of(self::post('/api/tasks', 'not json either')),
        );
    }

    public function testAnEmptyBodyFingerprints(): void
    {
        self::assertSame(
            RequestFingerprint::of(self::post('/api/tasks', '')),
            RequestFingerprint::of(self::post('/api/tasks', '   ')),
        );
    }

    private static function post(string $path, string $body): Request
    {
        return Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }
}
