<?php
declare(strict_types=1);

namespace App\Infrastructure\Animation\Veo;

final readonly class GoogleVeoConfig
{
    public function __construct(
        public string $projectId,
        public string $location,
        public string $modelId,
        public string $gcsBucket,
        public string $outputPrefix = 'veo-output',
    ) {}

    public function baseUrl(): string
    {
        return sprintf('https://%s-aiplatform.googleapis.com', $this->location);
    }

    public function predictLongRunningUrl(): string
    {
        // Ejemplo oficial: .../publishers/google/models/veo-3.0-generate-preview:predictLongRunning
        return sprintf(
            '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:predictLongRunning',
            $this->baseUrl(),
            $this->projectId,
            $this->location,
            $this->modelId
        );
    }

    public function fetchPredictOperationUrl(): string
    {
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:fetchPredictOperation',
            $this->location,
            $this->projectId,
            $this->location,
            $this->modelId,
        );
    }

    public function outputGcsUriPrefix(string $jobId): string
    {
        // prefix estilo: gs://bucket/veo-output/<jobId>/
        return sprintf('gs://%s/%s/%s/', $this->gcsBucket, trim($this->outputPrefix, '/'), $jobId);
    }
}
