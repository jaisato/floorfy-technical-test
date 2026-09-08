<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Callback;

use App\Task\Application\Callback\CallbackDelivery;
use App\Task\Application\Callback\CallbackDeliveryFailed;
use App\Task\Application\Callback\CallbackRequest;
use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Task\Infrastructure\Media\UrlNotFetchable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POSTs a notification, signed, to a URL the client gave us.
 *
 * A callback URL is an outbound request to an address the caller chose, which
 * is the same thing an image URL is: without the guard the API would POST to
 * the cloud metadata endpoint or to whatever listens on the private network on
 * anyone's say-so. So the URL faces the same rules (http/https, public address,
 * ports 80 and 443), the connection is pinned to the address that was checked,
 * and redirects are not followed - a permitted URL redirecting inward would
 * otherwise defeat the check.
 *
 * The notification is signed with HMAC-SHA256 under a per-deployment secret and
 * the signature travels in X-Task-Signature, so the receiver can tell a real
 * notification from a forged one - and, just as much, an altered one.
 *
 * What is signed is the metadata *and* the body, not the body alone. The
 * announced event is deliberately not derivable from the body: the body is the
 * task as it stands when the notification is delivered, so a `task.failed`
 * delivered after a retry has a body that says `pending`. Covering only the
 * body therefore left the one field the receiver cannot reconstruct as the one
 * field an on-path party could rewrite for free on an allowed `http://`
 * endpoint - turning a failure into a completion, or a notification of this run
 * into one of the last. The run number is in there for the same reason.
 */
final readonly class SignedHttpCallbackDelivery implements CallbackDelivery
{
    public const string SIGNATURE_HEADER = 'X-Task-Signature';
    public const string EVENT_HEADER = 'X-Task-Event';
    public const string TASK_HEADER = 'X-Task-Id';
    public const string RUN_HEADER = 'X-Task-Run';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PublicUrlGuard $urlGuard,
        #[Autowire(env: 'CALLBACK_SIGNING_SECRET')]
        private string $signingSecret,
        private int $timeoutSeconds = 10,
    ) {
    }

    public function deliver(CallbackRequest $request): void
    {
        if ('' === $this->signingSecret) {
            throw CallbackDeliveryFailed::unsigned();
        }

        try {
            $ips = $this->urlGuard->assertFetchable($request->url);
        } catch (UrlNotFetchable $e) {
            throw CallbackDeliveryFailed::refused($request->url, $e);
        }

        $body = json_encode($request->body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $event = 'task.'.$request->event;
        $run = (string) $request->generation;

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'floorfy-video-tasks',
                self::EVENT_HEADER => $event,
                self::TASK_HEADER => $request->taskId,
                self::RUN_HEADER => $run,
                self::SIGNATURE_HEADER => self::signature(
                    self::signedPayload($event, $request->taskId, $run, $body),
                    $this->signingSecret,
                ),
            ],
            'body' => $body,
            'max_redirects' => 0,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ];

        try {
            $response = $this->requestPinnedToAValidatedAddress($request->url, $ips, $options);
            $status = $response->getStatusCode();
            // The endpoint's answer is not read past the status: only whether it
            // accepted the notification matters, and an endpoint that streams a
            // large body back must not hold the worker.
            $response->cancel();
        } catch (TransportExceptionInterface $e) {
            throw CallbackDeliveryFailed::unreachable($request->url, $e);
        }

        // Any 2xx is an acceptance; a redirect is not followed, and so counts as
        // a refusal like any other status.
        if ($status < 200 || $status >= 300) {
            throw CallbackDeliveryFailed::status($request->url, $status);
        }
    }

    /**
     * The exact bytes the signature covers: the three headers that say which
     * notification this is, then the body, one per line.
     *
     * Newline-separated and in this order because every field before the body
     * is one line by construction - `task.` and a status, a UUID, a decimal
     * integer - so no combination of values can be read as another, and the
     * body is last so its own newlines cannot shift the boundaries.
     *
     * The receiver builds this from what it received: the two header values
     * verbatim, the run header, and the raw request body before any parsing.
     */
    public static function signedPayload(string $event, string $taskId, string $run, string $body): string
    {
        return implode("\n", [$event, $taskId, $run, $body]);
    }

    /**
     * What a receiver computes to verify the notification: "sha256=" followed
     * by the hex HMAC-SHA256 of signedPayload().
     */
    public static function signature(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Connects to one of the addresses the guard approved, not to whatever the
     * hostname resolves to a second time (see ImageDownloader for the DNS
     * rebinding this prevents). Every approved address gets a turn.
     *
     * @param list<string>         $ips
     * @param array<string, mixed> $options
     *
     * @throws TransportExceptionInterface
     */
    private function requestPinnedToAValidatedAddress(string $url, array $ips, array $options): ResponseInterface
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $needsPinning = \is_string($host) && false === filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP);

        if (!$needsPinning) {
            $response = $this->httpClient->request('POST', $url, $options);
            $response->getStatusCode();

            return $response;
        }

        $lastError = null;

        foreach ($ips as $ip) {
            try {
                $response = $this->httpClient->request('POST', $url, $options + ['resolve' => [$host => $ip]]);
                $response->getStatusCode();

                return $response;
            } catch (TransportExceptionInterface $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('No se pudo conectar con ninguna dirección validada para '.$url);
    }
}
