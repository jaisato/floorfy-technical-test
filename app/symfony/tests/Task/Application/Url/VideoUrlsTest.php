<?php

declare(strict_types=1);

namespace App\Tests\Task\Application\Url;

use App\Task\Application\Url\VideoUrls;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoUrlsTest extends TestCase
{
    private const string SECRET = 'a-video-signing-secret';
    private const int TTL = 3600;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-01-02T03:04:05+00:00');
    }

    public function testWithoutASecretTheUrlIsJustTheBaseAndThePath(): void
    {
        $urls = $this->urls('');

        self::assertFalse($urls->signingEnabled());
        self::assertSame('http://localhost:8080/videos/final_x.mp4', $urls->absolute('/videos/final_x.mp4'));
    }

    public function testATrailingSlashOnTheBaseIsNotDoubled(): void
    {
        self::assertSame(
            'http://localhost:8080/videos/final_x.mp4',
            new VideoUrls($this->clock, 'http://localhost:8080/', '', self::TTL)->absolute('/videos/final_x.mp4'),
        );
    }

    /** A part that has not been rendered has no URL, signed or otherwise. */
    public function testNothingInNothingOut(): void
    {
        self::assertNull($this->urls('')->absolute(null));
        self::assertNull($this->urls(self::SECRET)->absolute(null));
    }

    public function testWithASecretTheUrlCarriesADeadlineAndASignature(): void
    {
        $url = $this->urls(self::SECRET)->absolute('/videos/final_x.mp4');

        self::assertIsString($url);
        self::assertStringStartsWith('http://localhost:8080/videos/final_x.mp4?expires=', $url);
        self::assertMatchesRegularExpression('/[?&]sig=[0-9a-f]{64}$/', $url);
    }

    public function testTheDeadlineIsTheClockPlusTheTtl(): void
    {
        $url = (string) $this->urls(self::SECRET)->absolute('/videos/final_x.mp4');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame(
            (string) ($this->clock->now()->toDateTimeImmutable()->getTimestamp() + self::TTL),
            $query['expires'],
        );
    }

    public function testAUrlItMintedIsOneItAccepts(): void
    {
        $urls = $this->urls(self::SECRET);
        [$path, $expires, $signature] = self::parts((string) $urls->absolute('/videos/final_x.mp4'));

        self::assertTrue($urls->mayServe($path, $expires, $signature));
    }

    /** Without a secret nothing is checked; that is what "unsigned" means. */
    public function testWithoutASecretEverythingIsServed(): void
    {
        self::assertTrue($this->urls('')->mayServe('/videos/final_x.mp4', null, null));
    }

    public function testASignatureForAnotherVideoIsRefused(): void
    {
        $urls = $this->urls(self::SECRET);
        [, $expires, $signature] = self::parts((string) $urls->absolute('/videos/final_x.mp4'));

        self::assertFalse($urls->mayServe('/videos/final_y.mp4', $expires, $signature));
    }

    /** Moving the deadline out has to invalidate the signature, or it is not one. */
    public function testAnExtendedDeadlineIsRefused(): void
    {
        $urls = $this->urls(self::SECRET);
        [$path, $expires, $signature] = self::parts((string) $urls->absolute('/videos/final_x.mp4'));

        self::assertFalse($urls->mayServe($path, (string) ((int) $expires + 60), $signature));
    }

    public function testALinkStopsWorkingOnceItsDeadlinePasses(): void
    {
        $urls = $this->urls(self::SECRET);
        [$path, $expires, $signature] = self::parts((string) $urls->absolute('/videos/final_x.mp4'));

        self::assertTrue($urls->mayServe($path, $expires, $signature));

        $this->clock->advance(self::TTL + 1);

        self::assertFalse($urls->mayServe($path, $expires, $signature));
    }

    /** Right on the deadline is still inside it. */
    public function testALinkIsValidUpToItsDeadline(): void
    {
        $urls = $this->urls(self::SECRET);
        [$path, $expires, $signature] = self::parts((string) $urls->absolute('/videos/final_x.mp4'));

        $this->clock->advance(self::TTL);

        self::assertTrue($urls->mayServe($path, $expires, $signature));
    }

    public function testAUrlSignedWithAnotherSecretIsRefused(): void
    {
        [$path, $expires, $signature] = self::parts((string) $this->urls('some-other-secret-16')->absolute('/videos/final_x.mp4'));

        self::assertFalse($this->urls(self::SECRET)->mayServe($path, $expires, $signature));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function unusableParameters(): iterable
    {
        yield 'nothing at all' => [null, null];
        yield 'no signature' => ['4102444800', null];
        yield 'no deadline' => [null, str_repeat('0', 64)];
        yield 'deadline is not a number' => ['soon', str_repeat('0', 64)];
        yield 'deadline is enormous' => ['999999999999999', str_repeat('0', 64)];
        yield 'empty signature' => ['4102444800', ''];
    }

    #[DataProvider('unusableParameters')]
    public function testAMissingOrMalformedParameterIsRefused(?string $expires, ?string $signature): void
    {
        self::assertFalse($this->urls(self::SECRET)->mayServe('/videos/final_x.mp4', $expires, $signature));
    }

    private function urls(string $secret): VideoUrls
    {
        return new VideoUrls($this->clock, 'http://localhost:8080', $secret, self::TTL);
    }

    /** @return array{string, string, string} path, expires, signature */
    private static function parts(string $url): array
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertIsString($query['expires'] ?? null);
        self::assertIsString($query['sig'] ?? null);

        return [$path, $query['expires'], $query['sig']];
    }
}
