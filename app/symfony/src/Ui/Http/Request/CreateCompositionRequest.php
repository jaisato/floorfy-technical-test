<?php
declare(strict_types=1);

namespace App\Ui\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateCompositionRequest
{
    /**
     * @param list<string> $videoUrls
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1, max: 20)]
        #[Assert\All([
            new Assert\Url(),
        ])]
        public array $videoUrls,
    ) {}

    public static function fromArray(array $payload): self
    {
        $urls = $payload['videoUrls'] ?? [];
        return new self(is_array($urls) ? array_values($urls) : []);
    }
}
