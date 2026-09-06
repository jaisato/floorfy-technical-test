<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Callback;

use App\Task\Application\Callback\CallbackDeliveryFailed;
use PHPUnit\Framework\TestCase;

/**
 * What a failed delivery says, and what it must not.
 *
 * The message goes to the application log and, once the retries are spent, into
 * the failure transport, both readable by whoever operates them. A callback URL
 * belongs to a client, and a token in its userinfo or a query parameter is the
 * ordinary way to write one - so the endpoint is named without them.
 */
final class CallbackDeliveryFailedTest extends TestCase
{
    public function testAStatusFailureNamesTheEndpointWithoutItsCredentials(): void
    {
        $failure = CallbackDeliveryFailed::status('https://bot:s3cr3t@client.example:8443/hook?token=abcdef', 500);

        self::assertStringContainsString('https://client.example:8443/hook?…', $failure->getMessage());
        self::assertStringContainsString('HTTP 500', $failure->getMessage());
        self::assertStringNotContainsString('s3cr3t', $failure->getMessage());
        self::assertStringNotContainsString('abcdef', $failure->getMessage());
        self::assertFalse($failure->isPermanent(), 'a 500 may be a 200 in a minute');
    }

    public function testAnUnreachableEndpointIsNamedTheSameWay(): void
    {
        $failure = CallbackDeliveryFailed::unreachable(
            'https://bot:s3cr3t@client.example/hook?key=abcdef',
            new \RuntimeException('Connection timed out'),
        );

        self::assertStringContainsString('https://client.example/hook?…', $failure->getMessage());
        self::assertStringNotContainsString('s3cr3t', $failure->getMessage());
        self::assertStringNotContainsString('abcdef', $failure->getMessage());
        self::assertStringContainsString('Connection timed out', $failure->getMessage());
    }

    /** A URL with nothing to hide reads exactly as it was written. */
    public function testAnOrdinaryUrlIsLeftAlone(): void
    {
        self::assertStringContainsString(
            'https://client.example/hook respondió HTTP 404',
            CallbackDeliveryFailed::status('https://client.example/hook', 404)->getMessage(),
        );
    }

    /** Unparseable is reported as such rather than echoed into the log. */
    public function testAUrlThatWillNotParseIsNotRepeated(): void
    {
        $failure = CallbackDeliveryFailed::status('http://:::not a url', 500);

        self::assertStringContainsString('(URL ilegible)', $failure->getMessage());
        self::assertStringNotContainsString('not a url', $failure->getMessage());
    }

    /**
     * The refusal is the guard's own message about a URL it would not fetch,
     * and it is permanent: no number of retries makes a private address public.
     */
    public function testARefusedUrlIsPermanent(): void
    {
        $failure = CallbackDeliveryFailed::refused('http://10.0.0.1/hook', new \RuntimeException('dirección privada'));

        self::assertTrue($failure->isPermanent());
        self::assertStringContainsString('dirección privada', $failure->getMessage());
    }

    /**
     * Redacting the URL we were handed is only half of it: Symfony's transport
     * exceptions quote the whole request URL back, so the cause's own message
     * put the credentials straight back into the line.
     */
    public function testTheCausesMessageIsRedactedToo(): void
    {
        $failure = CallbackDeliveryFailed::unreachable(
            'https://client.example/hook',
            new \RuntimeException('Could not resolve host for "https://bot:s3cr3t@client.example/hook?token=abcdef".'),
        );

        self::assertStringNotContainsString('s3cr3t', $failure->getMessage());
        self::assertStringNotContainsString('abcdef', $failure->getMessage());
        self::assertStringContainsString('Could not resolve host for "https://client.example/hook?…".', $failure->getMessage());
    }

    /** The class of what failed is the part of the chain worth keeping. */
    public function testTheCauseIsNamedByClassRatherThanChained(): void
    {
        $failure = CallbackDeliveryFailed::unreachable('https://client.example/hook', new \DomainException('timeout'));

        self::assertStringContainsString('DomainException', $failure->getMessage());
        self::assertNull(
            $failure->getPrevious(),
            'a chained cause travels into the failure transport with its message intact',
        );
    }
}
