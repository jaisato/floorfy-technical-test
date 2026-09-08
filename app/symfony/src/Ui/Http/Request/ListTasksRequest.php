<?php

declare(strict_types=1);

namespace App\Ui\Http\Request;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\ValueObject\DateTimeValue;
use App\Task\Application\Query\ListVideoTasksQuery;
use App\Task\Application\ReadModel\TaskListing;
use App\Task\Domain\Enum\VideoTaskStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The query string of GET /api/tasks, validated before anything reaches SQL.
 *
 * Every value arrives as a string (or as an array, when a client repeats a
 * parameter), so the constraints work on the raw text and the typed query is
 * built only once they have all passed.
 */
final class ListTasksRequest
{
    /**
     * A calendar date, or a date-time with optional seconds, fraction and zone
     * (RFC 3339 / ISO 8601). A date alone is the start of that day, in UTC.
     */
    private const string ISO_8601 = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    private const string POSITIVE_INTEGER = '/^[1-9]\d{0,8}$/';

    public function __construct(
        #[Assert\Type(type: 'string', message: 'El filtro "status" debe ser un texto.')]
        #[Assert\Choice(callback: [self::class, 'statuses'], message: 'El filtro "status" no es un estado conocido.')]
        public mixed $status,
        #[Assert\Type(type: 'string', message: 'El filtro "createdFrom" debe ser una fecha ISO 8601.')]
        #[Assert\Regex(pattern: self::ISO_8601, message: 'El filtro "createdFrom" debe ser una fecha ISO 8601.')]
        public mixed $createdFrom,
        #[Assert\Type(type: 'string', message: 'El filtro "createdTo" debe ser una fecha ISO 8601.')]
        #[Assert\Regex(pattern: self::ISO_8601, message: 'El filtro "createdTo" debe ser una fecha ISO 8601.')]
        public mixed $createdTo,
        #[Assert\Type(type: 'string', message: 'El parámetro "page" debe ser un entero positivo.')]
        #[Assert\Regex(pattern: self::POSITIVE_INTEGER, message: 'El parámetro "page" debe ser un entero positivo.')]
        public mixed $page,
        #[Assert\Type(type: 'string', message: 'El parámetro "limit" debe ser un entero positivo.')]
        #[Assert\Regex(pattern: self::POSITIVE_INTEGER, message: 'El parámetro "limit" debe ser un entero positivo.')]
        public mixed $limit,
    ) {
    }

    /** @param array<string, mixed> $query the request's query parameters */
    public static function fromQuery(array $query): self
    {
        return new self(
            $query['status'] ?? null,
            $query['createdFrom'] ?? null,
            $query['createdTo'] ?? null,
            $query['page'] ?? null,
            $query['limit'] ?? null,
        );
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_column(VideoTaskStatus::cases(), 'value');
    }

    /**
     * The regex admits the shape; this admits the calendar (2026-13-45 has the
     * shape) and requires the range to run forwards.
     */
    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        $from = self::parseDate($this->createdFrom);
        $to = self::parseDate($this->createdTo);

        if (\is_string($this->createdFrom) && null === $from) {
            $context->buildViolation('El filtro "createdFrom" no es una fecha válida.')->atPath('createdFrom')->addViolation();
        }

        if (\is_string($this->createdTo) && null === $to) {
            $context->buildViolation('El filtro "createdTo" no es una fecha válida.')->atPath('createdTo')->addViolation();
        }

        if (null !== $from && null !== $to && $from->toDateTimeImmutable() > $to->toDateTimeImmutable()) {
            $context->buildViolation('El filtro "createdFrom" es posterior a "createdTo".')->atPath('createdFrom')->addViolation();
        }
    }

    /**
     * Only safe to call once the validator has accepted this object.
     *
     * A page size above the ceiling is capped rather than refused: the client
     * asked for "as many as possible", and the response says how many that was.
     */
    public function toQuery(): ListVideoTasksQuery
    {
        return new ListVideoTasksQuery(new TaskListing(
            \is_string($this->status) ? VideoTaskStatus::from($this->status) : null,
            self::parseDate($this->createdFrom),
            self::parseDate($this->createdTo),
            \is_string($this->page) ? (int) $this->page : 1,
            \is_string($this->limit) ? min((int) $this->limit, TaskListing::MAX_LIMIT) : TaskListing::DEFAULT_LIMIT,
        ));
    }

    private static function parseDate(mixed $value): ?DateTimeValue
    {
        if (!\is_string($value) || 1 !== preg_match(self::ISO_8601, $value)) {
            return null;
        }

        try {
            $parsed = DateTimeValue::fromString($value);
        } catch (DomainException) {
            return null;
        }

        // PHP's parser forgives an impossible day (2026-02-30 becomes March 2nd);
        // a filter that quietly moves is worse than one that is refused.
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? null : $parsed;
    }
}
