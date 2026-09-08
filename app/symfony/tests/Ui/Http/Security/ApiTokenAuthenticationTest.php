<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Security;

use App\Tests\Support\OverridesEnvironment;
use App\Ui\Http\Response\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The optional API key, over HTTP.
 *
 * API_TOKENS is set before the client is created, because that is when the
 * kernel reads it; tearDown puts the environment back so the rest of the suite
 * still sees an open API.
 */
final class ApiTokenAuthenticationTest extends WebTestCase
{
    use OverridesEnvironment;

    private const string SECRET = 'a-secret-of-at-least-16';
    private const string TOKENS = 'web:'.self::SECRET;

    private ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironment();
    }

    /** The default. Nothing about this feature may change an open deployment. */
    public function testWithoutTokensTheApiIsOpen(): void
    {
        $this->clientWithTokens('');

        $this->client()->request('GET', '/api/tasks');

        self::assertResponseIsSuccessful();
    }

    public function testWithTokensARequestWithoutCredentialsIsRefused(): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', '/api/tasks');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(ApiProblem::CONTENT_TYPE, $this->client()->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Bearer', (string) $this->client()->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testABearerTokenIsAccepted(): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', '/api/tasks', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET]);

        self::assertResponseIsSuccessful();
    }

    public function testAnApiKeyHeaderIsAccepted(): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', '/api/tasks', server: ['HTTP_X_API_KEY' => self::SECRET]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function badCredentials(): iterable
    {
        yield 'wrong bearer' => [['HTTP_AUTHORIZATION' => 'Bearer not-the-secret-at-all']];
        yield 'wrong api key' => [['HTTP_X_API_KEY' => 'not-the-secret-at-all']];
        yield 'the client name' => [['HTTP_X_API_KEY' => 'web']];
        yield 'another scheme' => [['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('web:'.self::SECRET)]];
        yield 'empty api key' => [['HTTP_X_API_KEY' => '']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('badCredentials')]
    public function testAWrongCredentialIsRefused(array $headers): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', '/api/tasks', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * A caller must not be able to tell "there is no such key" from "you sent
     * none": that difference is an oracle and none of its business.
     */
    public function testTheRefusalReadsTheSameWhicheverWayItFailed(): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', '/api/tasks');
        $missing = (string) $this->client()->getResponse()->getContent();

        $this->client()->request('GET', '/api/tasks', server: ['HTTP_X_API_KEY' => 'not-the-secret-at-all']);
        $wrong = (string) $this->client()->getResponse()->getContent();

        self::assertSame($missing, $wrong);
    }

    /** A refusal must happen before anything is written. */
    public function testAnUnauthenticatedCreateCreatesNothing(): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request(
            'POST',
            '/api/tasks',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"images":[{"url":"https://example.com/a.png","transition":"pan"}]}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPaths(): iterable
    {
        yield 'liveness' => ['/health'];
        yield 'readiness' => ['/health/ready'];
    }

    /** A prober has no token, and an outage must not look like a healthy 401. */
    #[DataProvider('publicPaths')]
    public function testTheHealthEndpointsStayPublic(string $path): void
    {
        $this->clientWithTokens(self::TOKENS);

        $this->client()->request('GET', $path);

        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client()->getResponse()->getStatusCode());
    }

    /**
     * Idempotency-Keys are scoped to the caller, and with authentication on
     * the caller is a real name: two clients that happen to choose the same
     * key must each get their own task, not one client's answer.
     */
    public function testTwoClientsDoNotShareAnIdempotencyKey(): void
    {
        $this->clientWithTokens('web:'.self::SECRET.',batch:another-secret-of-16');

        $first = $this->createWith(self::SECRET);
        $second = $this->createWith('another-secret-of-16');

        self::assertNotSame('', $first);
        self::assertNotSame($first, $second);
    }

    private function createWith(string $secret): string
    {
        $this->client()->request(
            'POST',
            '/api/tasks',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_API_KEY' => $secret,
                'HTTP_IDEMPOTENCY_KEY' => 'the-same-key-for-both',
            ],
            content: '{"images":[{"url":"https://example.com/a.png","transition":"pan"}]}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $body = json_decode((string) $this->client()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertIsString($body['task_id']);

        return $body['task_id'];
    }

    private function clientWithTokens(string $tokens): void
    {
        $this->overrideEnv('API_TOKENS', $tokens);

        $this->client = self::createClient();
    }

    private function client(): KernelBrowser
    {
        self::assertNotNull($this->client);

        return $this->client;
    }
}
