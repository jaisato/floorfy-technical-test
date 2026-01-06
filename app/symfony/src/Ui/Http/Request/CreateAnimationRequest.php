<?php
declare(strict_types=1);

namespace App\Ui\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateAnimationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url]
        public string $imageUrl,

        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        public string $prompt,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            (string)($payload['imageUrl'] ?? ''),
            (string)($payload['prompt'] ?? ''),
        );
    }
}
