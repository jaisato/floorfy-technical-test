<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Google;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;

final class GoogleAccessTokenProvider
{
    private FetchAuthTokenInterface $creds;

    public function __construct()
    {
        // Cloud-platform scope para Vertex AI + GCS
        $this->creds = ApplicationDefaultCredentials::getCredentials([
            'https://www.googleapis.com/auth/cloud-platform',
        ]);
    }

    public function getAccessToken(): string
    {
        $token = $this->creds->fetchAuthToken();

        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('No se pudo obtener access_token vía ADC (GOOGLE_APPLICATION_CREDENTIALS).');
        }

        return $accessToken;
    }
}
