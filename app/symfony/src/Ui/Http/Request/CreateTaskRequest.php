<?php

declare(strict_types=1);

namespace App\Ui\Http\Request;

use App\Task\Domain\Enum\Transition;
use App\Task\Domain\ValueObject\RenderOptions;
use App\Ui\Http\Validation\PublicHttpUrl;
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
        // A list, not merely an array. A JSON object passes is_array() and
        // every constraint below it, and the parts are then assembled in the
        // order its members happen to sit in the document - so a client that
        // wrote {"2": …, "1": …} believing the keys ordered them gets a video
        // whose scenes run the other way, with nothing said. The contract is a
        // list, so the format has to be one.
        #[Assert\Type(type: 'list', message: 'El campo "images" debe ser una lista.')]
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
                    // The one option that may differ per image: frame rate and
                    // size have to match across the parts for them to be
                    // concatenated at all.
                    'duration' => new Assert\Optional([
                        new Assert\Type('numeric'),
                        new Assert\Range(min: RenderOptions::MIN_DURATION, max: RenderOptions::MAX_DURATION),
                    ]),
                ],
                allowMissingFields: false,
                allowExtraFields: false,
            ),
        ])]
        public mixed $images,
        /**
         * Optional. Where the outcome is POSTed; faces the same rules as the
         * image URLs, checked here because a callback nobody can reach is only
         * discovered when there is nobody left to tell.
         *
         * No TLD is demanded of it: a receiver named by a public address literal
         * is a legitimate receiver, and whether the host is reachable at all is
         * the guard's call, not a spelling rule's.
         */
        #[Assert\NotBlank(allowNull: true, message: 'El campo "callback_url" no puede estar vacío.')]
        #[Assert\Type(type: 'string', message: 'El campo "callback_url" debe ser una URL.')]
        #[Assert\Length(max: self::MAX_URL_LENGTH)]
        #[Assert\Url(requireTld: false, message: 'El campo "callback_url" debe ser una URL http o https.')]
        #[PublicHttpUrl]
        public mixed $callbackUrl = null,
        /**
         * Optional render options for the whole task. Anything left out keeps
         * the deployment's default.
         */
        #[Assert\Type(type: 'array', message: 'El campo "options" debe ser un objeto.')]
        #[Assert\Collection(
            fields: [
                'duration' => new Assert\Optional([
                    new Assert\Type('numeric'),
                    new Assert\Range(min: RenderOptions::MIN_DURATION, max: RenderOptions::MAX_DURATION),
                ]),
                'fps' => new Assert\Optional([
                    new Assert\Type('integer'),
                    new Assert\Choice(choices: RenderOptions::FRAME_RATES),
                ]),
                'resolution' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Choice(choices: RenderOptions::RESOLUTIONS),
                ]),
                'crossfade' => new Assert\Optional([
                    new Assert\Type('numeric'),
                    new Assert\Range(min: 0, max: RenderOptions::MAX_CROSSFADE),
                ]),
            ],
            allowMissingFields: true,
            allowExtraFields: false,
        )]
        public mixed $options = null,
    ) {
    }

    public static function fromArray(mixed $payload): self
    {
        if (!\is_array($payload)) {
            return new self(null);
        }

        // An explicit null is the same as leaving it out; an empty string is
        // not a URL and is left for the constraints to refuse.
        // An absent "options" is not the same as an empty one only in that it
        // skips the collection constraint; both end up as the defaults.
        return new self($payload['images'] ?? null, $payload['callback_url'] ?? null, $payload['options'] ?? null);
    }

    /** Only safe to call once the validator has accepted this object. */
    public function callbackUrl(): ?string
    {
        return \is_string($this->callbackUrl) ? $this->callbackUrl : null;
    }

    /**
     * The validated task-wide options, as a map the domain merges over its
     * defaults. Only safe to call once the validator has accepted this object.
     *
     * @return array<string, mixed>
     */
    public function optionOverrides(): array
    {
        if (!\is_array($this->options)) {
            return [];
        }

        $overrides = [];

        foreach (['duration', 'fps', 'resolution', 'crossfade'] as $name) {
            if (\array_key_exists($name, $this->options)) {
                $overrides[$name] = self::normalise($name, $this->options[$name]);
            }
        }

        return $overrides;
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
     * @return list<array{url: string, transition: string, duration?: float}>
     */
    public function toImageList(): array
    {
        if (!\is_array($this->images)) {
            return [];
        }

        $images = [];

        foreach ($this->images as $specification) {
            if (!\is_array($specification)) {
                continue;
            }

            $url = $specification['url'] ?? null;
            $transition = $specification['transition'] ?? null;

            if (!\is_string($url) || !\is_string($transition)) {
                continue;
            }

            $image = ['url' => $url, 'transition' => $transition];
            $duration = self::seconds($specification['duration'] ?? null);

            if (null !== $duration) {
                $image['duration'] = $duration;
            }

            $images[] = $image;
        }

        return $images;
    }

    /**
     * Seconds arrive as either JSON type; "fps" is a count and stays an int
     * (its constraint is Type('integer'), so a string never gets this far).
     */
    private static function normalise(string $name, mixed $value): mixed
    {
        return 'fps' === $name ? $value : self::seconds($value) ?? $value;
    }

    /**
     * A duration as a float, whichever way the client wrote it.
     *
     * The constraint on these fields is Type('numeric'), which accepts "3.5"
     * as readily as 3.5 - and Range coerces it to compare - so a request with
     * a quoted number is a valid request that asked for 3.5 seconds. It used
     * to be dropped here instead, silently, and the task was rendered with the
     * deployment's default: accepted, acknowledged, and not what was asked for.
     */
    private static function seconds(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || \is_string($value) && is_numeric($value)
            ? (float) $value
            : null;
    }
}
