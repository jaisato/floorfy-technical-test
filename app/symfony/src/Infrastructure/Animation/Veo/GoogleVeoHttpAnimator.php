<?php
declare(strict_types=1);

namespace App\Infrastructure\Animation\Veo;

use App\Animation\Domain\Port\VeoAnimator;
use App\Animation\Domain\Port\VeoResult;
use App\Shared\Domain\ValueObject\UrlValue;
use App\Shared\Infrastructure\Google\GoogleAccessTokenProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GoogleVeoHttpAnimator implements VeoAnimator
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly GoogleAccessTokenProvider $tokenProvider,
        private readonly GoogleVeoConfig $config,
    ) {}

    public function requestAnimation(UrlValue $imageUrl, string $prompt, ?int $seed = null): string
    {
        // Descargamos la imagen y la mandamos en base64 (patrón del ejemplo oficial)
        [$imageBase64, $imageMimeType] = $this->downloadImageAsBase64AndMime($imageUrl->value);

        $accessToken = $this->tokenProvider->getAccessToken();

        $jobId = bin2hex(random_bytes(16));
        $storageUri = $this->config->outputGcsUriPrefix($jobId);

        $payload = [
            'instances' => [[
                'prompt' => $prompt,
                'image' => [
                    'bytesBase64Encoded' => $imageBase64,
                    'mimeType' => $imageMimeType,
                ],
            ]],
            'parameters' => [
                'sampleCount' => 1,
                'storageUri' => $this->config->outputGcsUriPrefix($jobId),
                // 'aspectRatio' => '16:9',
                // 'durationSeconds' => 5,
            ],
        ];

        $resp = $this->http->request('POST', $this->config->predictLongRunningUrl(), [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = $resp->toArray(false);

        $operationName = (string)($data['name'] ?? '');
        if ($operationName === '') {
            throw new \RuntimeException('Vertex AI no devolvió operation name.');
        }

        return $operationName;
    }

    private function downloadImageAsBase64AndMime(string $imageUrl): array
    {
        $response = $this->http->request('GET', $imageUrl);
        $bytes = $response->getContent();

        $headers = $response->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? null;
        $mime = $contentType ? trim(explode(';', $contentType)[0]) : null;

        if (!$mime) {
            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($bytes) ?: 'image/jpeg';
        }

        return [base64_encode($bytes), $mime];
    }

    public function pollAnimation(string $operationName): VeoResult
    {
        $accessToken = $this->tokenProvider->getAccessToken();

        // Ejemplo oficial: POST :fetchPredictOperation { operationName: "projects/.../operations/..." } :contentReference[oaicite:12]{index=12}
        $resp = $this->http->request('POST', $this->config->fetchPredictOperationUrl(), [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json, charset=utf-8',
            ],
            'json' => [
                'operationName' => $operationName,
            ],
        ]);

        $data = $resp->toArray(false);

        var_dump($data);

        if (($data['done'] ?? false) !== true) {
            return VeoResult::running();
        }

        if (isset($data['error'])) {
            return VeoResult::failed($data['error']['message'] ?? 'Unknown Veo error');
        }

        if (isset($data['error']['message'])) {
            return VeoResult::failed((string)$data['error']['message']);
        }

        $video = $data['response']['videos'][0] ?? null;
        $gcsUri = $video['gcsUri'] ?? null;
        $mimeType = $video['mimeType'] ?? null;

        if ($gcsUri) {
            $videoUrl = $this->gcsUriToHttp($gcsUri);
            return VeoResult::succeeded(videoUrl: $videoUrl, gcsUri: $gcsUri);
        }

        return VeoResult::failed('Resultado sin gcsUri en response.videos[0]');
    }

    private function gcsUriToHttp(string $gcsUri): string
    {
        // gs://bucket/obj/path.mp4
        $without = substr($gcsUri, 5);
        [$bucket, $object] = explode('/', $without, 2);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $object)));

        return sprintf('https://storage.googleapis.com/%s/%s', rawurlencode($bucket), $encoded);
    }
}
