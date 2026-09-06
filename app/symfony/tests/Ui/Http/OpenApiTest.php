<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http;

use App\Tests\Support\OverridesEnvironment;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The description of the API, checked against the API.
 *
 * A document that is written by hand rots the moment somebody adds a route; the
 * point of this test is that adding one without documenting it fails here
 * rather than on the day a client generates a broken SDK from it.
 */
final class OpenApiTest extends WebTestCase
{
    use OverridesEnvironment;

    /** Documented elsewhere on purpose: probes, files and the document itself. */
    private const array NOT_PART_OF_THE_CONTRACT = ['health_live', 'health_ready', 'videos_serve', 'api_doc'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheDocumentIsServedAsJson(): void
    {
        $this->client->request('GET', '/api/doc.json');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testItIsAnOpenApiThreeDocument(): void
    {
        $document = $this->document();

        self::assertIsString($document['openapi'] ?? null);
        self::assertStringStartsWith('3.', $document['openapi']);
        self::assertIsArray($document['info'] ?? null);
        self::assertNotSame('', $document['info']['title'] ?? '');
    }

    /**
     * Every route the router knows, with the methods it accepts, has to be in
     * the document. This is the assertion that turns "we have an OpenAPI file"
     * into "the OpenAPI file is true".
     */
    public function testEveryRouteIsDocumented(): void
    {
        $paths = $this->document()['paths'] ?? [];

        self::assertIsArray($paths);

        $missing = [];

        foreach ($this->routes() as [$name, $path, $methods]) {
            foreach ($methods as $method) {
                $operation = $paths[$path][strtolower($method)] ?? null;

                if (!\is_array($operation)) {
                    $missing[] = \sprintf('%s %s (%s)', $method, $path, $name);
                }
            }
        }

        self::assertSame([], $missing, 'Rutas sin documentar en /api/doc.json: '.implode(', ', $missing));
    }

    /** A summary is what a reader sees first; an operation without one says nothing. */
    public function testEveryOperationSaysWhatItDoes(): void
    {
        foreach ($this->operations() as $label => $operation) {
            self::assertNotSame('', $operation['summary'] ?? '', $label.' no tiene summary.');
        }
    }

    /**
     * A response block with no content is a placeholder: it tells a client
     * nothing about what it will receive.
     */
    public function testEveryDocumentedResponseHasABodyOrIsEmptyOnPurpose(): void
    {
        foreach ($this->operations() as $label => $operation) {
            $responses = $operation['responses'] ?? [];

            self::assertIsArray($responses);
            self::assertNotSame([], $responses, $label.' no documenta ninguna respuesta.');

            foreach ($responses as $status => $response) {
                self::assertIsArray($response, \sprintf('%s: la respuesta %s no es un objeto.', $label, $status));

                $resolved = $this->resolved($response);

                self::assertNotSame('', $resolved['description'] ?? '', \sprintf('%s: la respuesta %s no tiene descripción.', $label, $status));

                if (Response::HTTP_NO_CONTENT === (int) $status) {
                    continue;
                }

                self::assertArrayHasKey('content', $resolved, \sprintf('%s: la respuesta %s no dice qué devuelve.', $label, $status));
            }
        }
    }

    /** Every write can fail validation, and every read can miss. */
    public function testTheErrorsAClientMustHandleAreDocumented(): void
    {
        $paths = $this->document()['paths'] ?? [];

        self::assertIsArray($paths);

        foreach ($this->routes() as [$name, $path, $methods]) {
            foreach ($methods as $method) {
                $operation = $paths[$path][strtolower($method)] ?? null;

                self::assertIsArray($operation);
                self::assertArrayHasKey('401', $operation['responses'] ?? [], $name.' no documenta el 401 de la API key.');

                if (str_contains($path, '{id}')) {
                    self::assertArrayHasKey('404', $operation['responses'] ?? [], $name.' no documenta el 404.');
                }
            }
        }
    }

    /** The one contract every error in this API follows. */
    public function testTheProblemSchemaIsDeclared(): void
    {
        $schemas = $this->document()['components']['schemas'] ?? [];

        self::assertIsArray($schemas);
        self::assertArrayHasKey('Problem', $schemas);
        self::assertSame(['type', 'title', 'status', 'detail'], $schemas['Problem']['required'] ?? []);
    }

    /** Both ways of sending the API key, so a generated client offers them. */
    public function testBothSecuritySchemesAreDeclared(): void
    {
        $schemes = $this->document()['components']['securitySchemes'] ?? [];

        self::assertIsArray($schemes);
        self::assertSame('http', $schemes['bearer']['type'] ?? null);
        self::assertSame('X-API-Key', $schemes['apiKey']['name'] ?? null);
    }

    /** Reading the contract must not require a credential. */
    public function testTheDocumentIsPublicEvenWithApiTokensSet(): void
    {
        self::ensureKernelShutdown();
        $this->overrideEnv('API_TOKENS', 'web:a-secret-of-at-least-16');

        try {
            self::createClient()->request('GET', '/api/doc.json');

            self::assertResponseStatusCodeSame(Response::HTTP_OK);
        } finally {
            $this->restoreEnvironment();
        }
    }

    /**
     * The routes that make up the API's contract.
     *
     * @return list<array{string, string, list<string>}> name, path, methods
     */
    private function routes(): array
    {
        $router = self::getContainer()->get('router');

        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (\in_array($name, self::NOT_PART_OF_THE_CONTRACT, true) || !str_starts_with($route->getPath(), '/api/')) {
                continue;
            }

            $methods = $route->getMethods();

            self::assertNotSame([], $methods, $name.' no declara métodos.');

            $routes[] = [$name, $route->getPath(), array_values($methods)];
        }

        self::assertNotSame([], $routes, 'El router no expone ninguna ruta de API.');

        return $routes;
    }

    /**
     * @return array<string, array<string, mixed>> "METHOD path" => operation
     */
    private function operations(): array
    {
        $paths = $this->document()['paths'] ?? [];

        self::assertIsArray($paths);

        $operations = [];

        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);

            foreach ($methods as $method => $operation) {
                self::assertIsArray($operation);

                $operations[strtoupper((string) $method).' '.$path] = $operation;
            }
        }

        return $operations;
    }

    /**
     * A response written as a $ref is the component it points at; a reader -
     * and a code generator - follows it, so the check has to as well.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function resolved(array $response): array
    {
        $ref = $response['$ref'] ?? null;

        if (!\is_string($ref)) {
            return $response;
        }

        self::assertStringStartsWith('#/components/responses/', $ref);

        $component = $this->document()['components']['responses'][substr($ref, \strlen('#/components/responses/'))] ?? null;

        self::assertIsArray($component, $ref.' no existe.');

        return $component;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $this->client->request('GET', '/api/doc.json');

        $content = $this->client->getResponse()->getContent();

        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
