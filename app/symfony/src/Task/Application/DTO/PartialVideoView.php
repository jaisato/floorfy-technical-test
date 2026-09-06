<?php

declare(strict_types=1);

namespace App\Task\Application\DTO;

use App\Shared\Application\Redaction\Urls;

final readonly class PartialVideoView
{
    public function __construct(
        public string $id,
        public string $imageUrl,
        public string $transition,
        public string $status,
        public ?string $videoUrl,
        public ?string $error,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            // The source of an image is routinely presigned - an object store
            // puts the signature in the query, and a private origin sometimes
            // puts credentials in the userinfo - and this handed it back whole
            // to whoever reads the task. The listing exposes every task id, and
            // the API is open unless a deployment turns tokens on, so a caller
            // could walk the ids and collect the credential of every source
            // anybody had ever submitted. Redacted like `callback_url`, it
            // still answers what the field is for: which image this part
            // renders from.
            'image_url' => Urls::endpoint($this->imageUrl),
            'transition' => $this->transition,
            'status' => $this->status,
            'video_url' => $this->videoUrl,
            'error' => $this->error,
        ];
    }
}
