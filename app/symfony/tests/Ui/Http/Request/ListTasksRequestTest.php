<?php

declare(strict_types=1);

namespace App\Tests\Ui\Http\Request;

use App\Task\Application\ReadModel\TaskListing;
use App\Task\Domain\Enum\VideoTaskStatus;
use App\Ui\Http\Request\ListTasksRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ListTasksRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testAnEmptyQueryIsTheDefaultListing(): void
    {
        $request = ListTasksRequest::fromQuery([]);

        self::assertCount(0, $this->validator->validate($request));

        $listing = $request->toQuery()->listing;

        self::assertNull($listing->status);
        self::assertNull($listing->createdFrom);
        self::assertNull($listing->createdTo);
        self::assertSame(1, $listing->page);
        self::assertSame(TaskListing::DEFAULT_LIMIT, $listing->limit);
    }

    public function testEveryParameterIsCarriedIntoTheListing(): void
    {
        $request = ListTasksRequest::fromQuery([
            'status' => 'failed',
            'createdFrom' => '2026-01-02T00:00:00+01:00',
            'createdTo' => '2026-01-03',
            'page' => '3',
            'limit' => '50',
        ]);

        self::assertCount(0, $this->validator->validate($request));

        $listing = $request->toQuery()->listing;

        self::assertSame(VideoTaskStatus::FAILED, $listing->status);
        self::assertSame('2026-01-01T23:00:00+00:00', (string) $listing->createdFrom);
        self::assertSame('2026-01-03T00:00:00+00:00', (string) $listing->createdTo);
        self::assertSame(3, $listing->page);
        self::assertSame(50, $listing->limit);
    }

    /** "As many as possible" is answered with the ceiling, not refused. */
    public function testALimitAboveTheCeilingIsCapped(): void
    {
        $request = ListTasksRequest::fromQuery(['limit' => '500']);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame(TaskListing::MAX_LIMIT, $request->toQuery()->listing->limit);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown status' => [['status' => 'exploded'], 'status'];
        yield 'status repeated' => [['status' => ['pending', 'failed']], 'status'];
        yield 'from is not a date' => [['createdFrom' => 'yesterday'], 'createdFrom'];
        yield 'from has the shape but not the calendar' => [['createdFrom' => '2026-02-30'], 'createdFrom'];
        yield 'to is not a date' => [['createdTo' => '2026-01-02T25:00:00Z'], 'createdTo'];
        yield 'range runs backwards' => [['createdFrom' => '2026-01-03', 'createdTo' => '2026-01-02'], 'createdFrom'];
        yield 'page zero' => [['page' => '0'], 'page'];
        yield 'page negative' => [['page' => '-1'], 'page'];
        yield 'page is text' => [['page' => 'two'], 'page'];
        yield 'page repeated' => [['page' => ['1', '2']], 'page'];
        yield 'limit zero' => [['limit' => '0'], 'limit'];
        yield 'limit is a float' => [['limit' => '2.5'], 'limit'];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testABadParameterIsAViolationOnThatParameter(array $query, string $field): void
    {
        $violations = $this->validator->validate(ListTasksRequest::fromQuery($query));

        self::assertGreaterThan(0, \count($violations));

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains($field, $paths);
    }

    /** @return iterable<string, array{string, string}> */
    public static function dateSpellings(): iterable
    {
        yield 'date only' => ['2026-01-02', '2026-01-02T00:00:00+00:00'];
        yield 'zulu' => ['2026-01-02T03:04:05Z', '2026-01-02T03:04:05+00:00'];
        yield 'offset' => ['2026-01-02T04:04:05+01:00', '2026-01-02T03:04:05+00:00'];
        yield 'offset without colon' => ['2026-01-02T04:04:05+0100', '2026-01-02T03:04:05+00:00'];
        yield 'no seconds' => ['2026-01-02T03:04Z', '2026-01-02T03:04:00+00:00'];
        yield 'fraction' => ['2026-01-02T03:04:05.250Z', '2026-01-02T03:04:05+00:00'];
        yield 'space instead of T' => ['2026-01-02 03:04:05', '2026-01-02T03:04:05+00:00'];
        yield 'naive, read as utc' => ['2026-01-02T03:04:05', '2026-01-02T03:04:05+00:00'];
    }

    #[DataProvider('dateSpellings')]
    public function testEveryIsoSpellingIsReadAsAnInstant(string $input, string $expected): void
    {
        $request = ListTasksRequest::fromQuery(['createdFrom' => $input]);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame($expected, (string) $request->toQuery()->listing->createdFrom);
    }
}
