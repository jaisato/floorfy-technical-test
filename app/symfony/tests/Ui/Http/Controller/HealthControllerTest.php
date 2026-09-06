<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Controller;

use App\Shared\Application\Health\CheckResult;
use App\Shared\Application\Health\HealthCheck;
use App\Shared\Application\Health\ReadinessProbe;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liveness and readiness over HTTP.
 *
 * The probe is replaced so both outcomes can be served: on this machine ffmpeg
 * is not installed, and a test that only ever saw one of the two answers would
 * pin nothing.
 */
final class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testLivenessAnswersOkWithoutTouchingAnything(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], $this->body());
    }

    public function testReadinessIsOkWhenEveryCheckPasses(): void
    {
        $this->probeWith(self::check('database', true), self::check('ffmpeg', true));

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok', 'ffmpeg' => 'ok']], $this->body());
    }

    /**
     * 503, not 500: the application is fine, what it depends on is not, and a
     * load balancer reads the difference.
     */
    public function testReadinessIs503WithTheCheckThatFailed(): void
    {
        $this->probeWith(self::check('database', true), self::check('ffmpeg', false));

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(
            ['status' => 'unavailable', 'checks' => ['database' => 'ok', 'ffmpeg' => 'failed']],
            $this->body(),
        );
    }

    /** Whatever went wrong stays in the log; the body says which check, only. */
    public function testTheReasonForAFailureIsNotInTheBody(): void
    {
        $this->probeWith(self::check('database', false));

        $this->client->request('GET', '/health/ready');

        self::assertStringNotContainsString('Connection refused', (string) $this->client->getResponse()->getContent());
    }

    public function testTheReadinessAnswerIsNeverCached(): void
    {
        $this->probeWith(self::check('database', true));

        $this->client->request('GET', '/health/ready');

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function healthPaths(): iterable
    {
        yield 'liveness' => ['/health'];
        yield 'readiness' => ['/health/ready'];
    }

    /** Probers speak GET; anything else is a mistake worth reporting. */
    #[DataProvider('healthPaths')]
    public function testOnlyGetIsAllowed(string $path): void
    {
        $this->client->request('POST', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function probeWith(HealthCheck ...$checks): void
    {
        self::getContainer()->set(ReadinessProbe::class, new ReadinessProbe($checks, new RecordingLogger()));
    }

    private static function check(string $name, bool $ok): HealthCheck
    {
        return new readonly class($name, $ok) implements HealthCheck {
            public function __construct(private string $name, private bool $ok)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function check(): CheckResult
            {
                return $this->ok ? CheckResult::ok() : CheckResult::failed('Connection refused on db-1:3306');
            }
        };
    }

    /** @return array<mixed> */
    private function body(): array
    {
        $content = $this->client->getResponse()->getContent();

        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
