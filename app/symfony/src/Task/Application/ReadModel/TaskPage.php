<?php

declare(strict_types=1);

namespace App\Task\Application\ReadModel;

use App\Task\Application\DTO\VideoTaskSummaryView;

/**
 * One page of task summaries plus the figures a client needs to page on:
 * the total across every page, the page and limit actually applied, how many
 * pages that makes and whether there is a next one.
 */
final readonly class TaskPage
{
    public int $pages;
    public bool $hasNext;

    /** @param list<VideoTaskSummaryView> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $limit,
    ) {
        if ($total < 0 || $page < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Una página necesita un total no negativo, un número de página y un tamaño positivos.');
        }

        $this->pages = (int) ceil($total / $limit);
        $this->hasNext = $page < $this->pages;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (VideoTaskSummaryView $item): array => $item->toArray(),
                $this->items,
            ),
            'total' => $this->total,
            'page' => $this->page,
            'limit' => $this->limit,
            'pages' => $this->pages,
            'hasNext' => $this->hasNext,
        ];
    }
}
