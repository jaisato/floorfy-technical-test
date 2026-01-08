<?php
declare(strict_types=1);

namespace App\Ui\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateTaskRequest
{
    /**
     * @param list<array{url:mixed, transition:mixed}> $images
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([
            new Assert\Collection(fields: [
                'url' => [
                    new Assert\NotBlank(),
                    new Assert\Url(),
                ],
                'transition' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(choices: ['pan', 'zoom_in', 'zoom_out']),
                ],
            ], allowMissingFields: false, allowExtraFields: false),
        ])]
        public array $images,
    ) {}

    public static function fromArray(array $payload): self
    {
        $images = $payload['images'] ?? [];
        return new self(is_array($images) ? array_values($images) : []);
    }
}
