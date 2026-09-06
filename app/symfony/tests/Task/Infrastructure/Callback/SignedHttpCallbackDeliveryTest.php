<?php

declare(strict_types=1);

namespace App\Tests\Task\Infrastructure\Callback;

use App\Task\Application\Callback\CallbackDeliveryFailed;
use App\Task\Application\Callback\CallbackRequest;
use App\Task\Infrastructure\Callback\SignedHttpCallbackDelivery;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Tests\Support\LocalHttpServer;
use App\Tests\Support\LoopbackTargetPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Against a real receiver on 127.0.0.1: what it gets is the contract clients
 * verify signatures with, so a mocked client would only prove the mock.
 */
final class SignedHttpCallbackDeliveryTest extends TestCase
{
    private const string SECRET = 'test-signing-secret-0123456789';

    private static ?LocalHttpServer $server = null;

    /** @var list<string> */
    private array $recordings = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->recordings as $file) {
            @unlink($file);
        }
    }

    public function testItPostsTheSummarySignedAndLabelled(): void
    {
        $id = $this->recordingId();
        $body = ['task_id' => '0195c6a0-1c37-7000-8000-000000000001', 'status' => 'completed', 'progress' => ['percent' => 100]];

        $this->delivery()->deliver(new CallbackRequest(
            self::server()->url('/hook/record?id='.$id),
            '0195c6a0-1c37-7000-8000-000000000001',
            'completed',
            $body,
        ));

        $received = $this->recorded($id);

        self::assertSame('POST', $received['method']);
        self::assertSame('application/json', $received['content_type']);
        self::assertSame('task.completed', $received['headers']['x-task-event']);
        self::assertSame('0195c6a0-1c37-7000-8000-000000000001', $received['headers']['x-task-id']);

        // The receiver recomputes the HMAC over the exact bytes it got.
        self::assertSame(
            'sha256='.hash_hmac('sha256', $received['body'], self::SECRET),
            $received['headers']['x-task-signature'],
        );
        self::assertSame($body, json_decode($received['body'], true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheSignatureIsTheHexHmacOfTheBody(): void
    {
        self::assertSame(
            'sha256='.hash_hmac('sha256', '{"a":1}', 'k'),
            SignedHttpCallbackDelivery::signature('{"a":1}', 'k'),
        );
    }

    /**
     * A callback URL is an outbound request to an address the caller chose:
     * without the guard it is a way to POST to the private network. Refused
     * before a single packet is sent, and for good - retrying cannot help.
     */
    public function testAPrivateAddressIsRefusedWithoutARequest(): void
    {
        try {
            $this->delivery()->deliver(new CallbackRequest('http://169.254.169.254/latest/meta-data/', 'x', 'completed', []));
            self::fail('the metadata endpoint must be refused');
        } catch (CallbackDeliveryFailed $e) {
            self::assertTrue($e->isPermanent());
            self::assertStringContainsString('169.254.169.254', $e->getMessage());
        }
    }

    public function testAnOddPortIsRefused(): void
    {
        $delivery = new SignedHttpCallbackDelivery(HttpClient::create(), new PublicUrlGuard(), self::SECRET, 1);

        $this->expectException(CallbackDeliveryFailed::class);
        $this->expectExceptionMessageMatches('/Puerto no permitido/');

        $delivery->deliver(new CallbackRequest('http://8.8.8.8:8080/hook', 'x', 'completed', []));
    }

    /** Sending unsigned would hand receivers something they cannot verify. */
    public function testWithoutASigningSecretNothingIsSent(): void
    {
        $id = $this->recordingId();
        $delivery = new SignedHttpCallbackDelivery(HttpClient::create(), new PublicUrlGuard(new LoopbackTargetPolicy()), '', 5);

        try {
            $delivery->deliver(new CallbackRequest(self::server()->url('/hook/record?id='.$id), 'x', 'completed', []));
            self::fail('an unsigned notification must not be sent');
        } catch (CallbackDeliveryFailed $e) {
            self::assertTrue($e->isPermanent());
        }

        self::assertFileDoesNotExist(sys_get_temp_dir().'/floorfy-hook-'.$id.'.json');
    }

    public function testAServerErrorIsAFailureWorthRetrying(): void
    {
        try {
            $this->delivery()->deliver(new CallbackRequest(self::server()->url('/hook/fail'), 'x', 'failed', []));
            self::fail('a 503 is not an acceptance');
        } catch (CallbackDeliveryFailed $e) {
            self::assertFalse($e->isPermanent());
            self::assertStringContainsString('HTTP 503', $e->getMessage());
        }
    }

    /** A redirect is not followed: its target was not checked by the guard. */
    public function testARedirectIsNotFollowed(): void
    {
        try {
            $this->delivery()->deliver(new CallbackRequest(self::server()->url('/hook/redirect'), 'x', 'failed', []));
            self::fail('a redirect must not count as delivered');
        } catch (CallbackDeliveryFailed $e) {
            self::assertStringContainsString('HTTP 307', $e->getMessage());
        }

        self::assertFileDoesNotExist(sys_get_temp_dir().'/floorfy-hook-redirected.json');
    }

    public function testAnUnreachableEndpointIsAFailureWorthRetrying(): void
    {
        // A port nothing listens on: refused at the TCP level.
        $port = (int) parse_url(self::server()->baseUrl, \PHP_URL_PORT) + 1;

        try {
            $this->delivery()->deliver(new CallbackRequest('http://127.0.0.1:'.$port.'/hook', 'x', 'canceled', []));
            self::fail('a connection refused is not an acceptance');
        } catch (CallbackDeliveryFailed $e) {
            self::assertFalse($e->isPermanent());
        }
    }

    /** The worker cannot wait on a receiver that never answers. */
    public function testASlowEndpointIsCutOffAtTheTimeout(): void
    {
        $started = microtime(true);

        try {
            $this->delivery(1)->deliver(new CallbackRequest(self::server()->url('/hook/slow'), 'x', 'completed', []));
            self::fail('a receiver slower than the timeout must not count as delivered');
        } catch (CallbackDeliveryFailed $e) {
            self::assertFalse($e->isPermanent());
        }

        self::assertLessThan(2.5, microtime(true) - $started);
    }

    private function delivery(int $timeoutSeconds = 5): SignedHttpCallbackDelivery
    {
        return new SignedHttpCallbackDelivery(
            HttpClient::create(),
            new PublicUrlGuard(new LoopbackTargetPolicy()),
            self::SECRET,
            $timeoutSeconds,
        );
    }

    private function recordingId(): string
    {
        $id = bin2hex(random_bytes(6));
        $this->recordings[] = sys_get_temp_dir().'/floorfy-hook-'.$id.'.json';

        return $id;
    }

    /** @return array{method: string, content_type: string, headers: array<string, string>, body: string} */
    private function recorded(string $id): array
    {
        $file = sys_get_temp_dir().'/floorfy-hook-'.$id.'.json';
        self::assertFileExists($file, 'the receiver should have recorded the request');

        $decoded = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['method']);
        self::assertIsString($decoded['content_type']);
        self::assertIsArray($decoded['headers']);
        self::assertIsString($decoded['body']);

        $headers = [];
        foreach ($decoded['headers'] as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value);
            $headers[$name] = $value;
        }

        return ['method' => $decoded['method'], 'content_type' => $decoded['content_type'], 'headers' => $headers, 'body' => $decoded['body']];
    }

    private static function server(): LocalHttpServer
    {
        if (null === self::$server) {
            throw new \LogicException('the test server is not running');
        }

        return self::$server;
    }
}
