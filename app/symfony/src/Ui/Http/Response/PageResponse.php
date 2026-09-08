<?php

declare(strict_types=1);

namespace App\Ui\Http\Response;

use App\Task\Application\ReadModel\TaskPage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves a page as JSON and, in a `Link` header (RFC 8288), the URLs of the
 * neighbouring pages: `rel="next"` while there is one, `rel="prev"` from the
 * second page on. Each link is the request's own URL with `page` replaced, so
 * every filter the client sent travels with it.
 */
final class PageResponse
{
    private function __construct()
    {
    }

    public static function serve(Request $request, TaskPage $page): JsonResponse
    {
        $response = new JsonResponse($page->toArray());

        $links = self::links($request, $page);
        if ([] !== $links) {
            $response->headers->set('Link', implode(', ', $links));
        }

        return $response;
    }

    /** @return list<string> */
    public static function links(Request $request, TaskPage $page): array
    {
        $links = [];

        if ($page->page > 1) {
            $links[] = \sprintf('<%s>; rel="prev"', self::urlForPage($request, $page->page - 1));
        }

        if ($page->hasNext) {
            $links[] = \sprintf('<%s>; rel="next"', self::urlForPage($request, $page->page + 1));
        }

        return $links;
    }

    private static function urlForPage(Request $request, int $number): string
    {
        $query = $request->query->all();
        $query['page'] = $number;

        return $request->getUriForPath($request->getPathInfo()).'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }
}
