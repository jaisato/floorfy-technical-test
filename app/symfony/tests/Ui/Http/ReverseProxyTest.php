<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http;

use App\Tests\Support\OverridesEnvironment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API behind a reverse proxy.
 *
 * Two answers depend on who is asking and how they got here: the Link headers
 * of a listing are built from the request's scheme and host, and the creation
 * limit is keyed on the client's address while the API is open. Behind a load
 * balancer that terminates TLS, both arrive in X-Forwarded-* headers - and the
 * proxy's word is taken only once the deployment names its address in
 * SYMFONY_TRUSTED_PROXIES. Honouring the headers from anyone would let every
 * client choose its own address, and with it its own allowance, by sending one.
 */
final class ReverseProxyTest extends WebTestCase
{
    use OverridesEnvironment;

    /** The address the test client connects from, i.e. the proxy's when one is trusted. */
    private const string PROXY = '127.0.0.1';

    /** @var array<string, mixed> */
    private const array PAYLOAD = ['images' => [['url' => 'https://example.com/a.png', 'transition' => 'pan']]];

    private ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironment();
        // The kernel installs the trusted proxies in a static of Request, and
        // only when there are some: the next kernel would inherit these.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    }

    /** The default: nobody is trusted, so the headers are just headers. */
    public function testForwardedHeadersAreIgnoredUntilTheProxyIsTrusted(): void
    {
        $this->overrideEnv('RATE_LIMIT_TASK_CREATION', '2');
        $client = $this->client();
        $client->setServerParameter('HTTP_X_FORWARDED_PROTO', 'https');

        // Three "different" clients, all of them the same address to the limiter.
        $this->createTaskAs($client, '203.0.113.9');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->createTaskAs($client, '203.0.113.10');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->createTaskAs($client, '203.0.113.11');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $client->request('GET', '/api/tasks?limit=1');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('<http://localhost/api/tasks?limit=1&page=2>; rel="next"', $client->getResponse()->headers->get('Link'));
    }

    public function testBehindATrustedProxyTheClientIsWhoTheProxySays(): void
    {
        $this->overrideEnv('SYMFONY_TRUSTED_PROXIES', self::PROXY);
        $this->overrideEnv('RATE_LIMIT_TASK_CREATION', '2');
        $client = $this->client();
        $client->setServerParameter('HTTP_X_FORWARDED_PROTO', 'https');

        // Each forwarded address has its own allowance...
        $this->createTaskAs($client, '203.0.113.9');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->createTaskAs($client, '203.0.113.9');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->createTaskAs($client, '203.0.113.9');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->createTaskAs($client, '203.0.113.10');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // ...and the links point at the scheme the client actually used.
        $client->request('GET', '/api/tasks?limit=1');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('<https://localhost/api/tasks?limit=1&page=2>; rel="next"', $client->getResponse()->headers->get('Link'));
    }

    /** Trust is given to an address, not to whoever sends the headers. */
    public function testAnUntrustedAddressCannotSpeakForAProxy(): void
    {
        $this->overrideEnv('SYMFONY_TRUSTED_PROXIES', self::PROXY);
        $client = $this->client();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.7');
        $client->setServerParameter('HTTP_X_FORWARDED_PROTO', 'https');
        $this->createTaskAs($client, '203.0.113.9');
        $this->createTaskAs($client, '203.0.113.9');

        $client->request('GET', '/api/tasks?limit=1');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('<http://localhost/api/tasks?limit=1&page=2>; rel="next"', $client->getResponse()->headers->get('Link'));
    }

    /**
     * Booted after the environment is set, and kept between requests: the
     * limiter's counters live in memory in the test environment and a reboot
     * would start them over.
     */
    private function client(): KernelBrowser
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement('DELETE FROM partial_videos');
        $entityManager->getConnection()->executeStatement('DELETE FROM video_tasks');

        return $this->client;
    }

    private function createTaskAs(KernelBrowser $client, string $forwardedFor): void
    {
        $client->setServerParameter('HTTP_X_FORWARDED_FOR', $forwardedFor);
        $client->request(
            'POST',
            '/api/tasks',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(self::PAYLOAD, \JSON_THROW_ON_ERROR),
        );
    }
}
