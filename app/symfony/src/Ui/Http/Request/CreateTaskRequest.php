<?php

declare(strict_types=1);

namespace App\Ui\Http\Request;

use App\Task\Domain\Enum\Transition;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateTaskRequest
{
    /**
     * The database column is 2048 characters wide and so is this: a URL the
     * validator accepts must be one the INSERT accepts, or a legal-looking
     * request becomes a 500 from the worker instead of a 400 from the API.
     */
    public const int MAX_URL_LENGTH = 2048;

    /**
     * One task is one ffmpeg run per image plus a concatenation, all on one
     * worker. Without a ceiling, a single request decides how long every other
     * task waits.
     */
    public const int MAX_IMAGES = 20;

    /**
     * @param mixed $images the raw value, validated below; anything that is not
     *                      a well-formed list of image specifications is rejected with a 400
     */
    public function __construct(
        #[Assert\NotNull(message: 'Falta el campo "images".')]
        #[Assert\Type(type: 'array', message: 'El campo "images" debe ser una lista.')]
        #[Assert\Count(
            min: 1,
            max: self::MAX_IMAGES,
            minMessage: 'Se requiere al menos una imagen.',
            maxMessage: 'No se admiten más de {{ limit }} imágenes por tarea.',
        )]
        #[Assert\All([
            new Assert\Collection(
                fields: [
                    'url' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length(max: self::MAX_URL_LENGTH),
                        // requireTld is explicit because the default flipped to
                        // true in Symfony 7.1 and leaving it out is deprecated;
                        // with failOnDeprecation on, that deprecation is a
                        // failing test rather than a line in a log.
                        new Assert\Url(requireTld: true),
                    ],
                    'transition' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Choice(callback: [self::class, 'transitions']),
                    ],
                ],
                allowMissingFields: false,
                allowExtraFields: false,
            ),
        ])]
        public mixed $images,
    ) {
    }

    public static function fromArray(mixed $payload): self
    {
        return new self(\is_array($payload) ? ($payload['images'] ?? null) : null);
    }

    /** @return list<string> */
    public static function transitions(): array
    {
        return array_column(Transition::cases(), 'value');
    }

    /**
     * The validated images, normalised for the command bus.
     *
     * Only safe to call once the validator has accepted this object.
     *
     * @return list<array{url: string, transition: string}>
     */
    public function toImageList(): array
    {
        if (!\is_array($this->images)) {
            return [];
        }

        $images = [];

        foreach ($this->images as $image) {
            if (!\is_array($image)) {
                continue;
            }

            $url = $image['url'] ?? null;
            $transition = $image['transition'] ?? null;

            if (!\is_string($url) || !\is_string($transition)) {
                continue;
            }

            $images[] = ['url' => $url, 'transition' => $transition];
        }

        return $images;
    }
}
