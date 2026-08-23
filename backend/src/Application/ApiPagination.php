<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\HttpFoundation\Request;

final class ApiPagination
{
    /** @return array{page: int, limit: int} */
    public static function fromRequest(Request $request, int $defaultLimit = 20): array
    {
        return ['page' => max(1, $request->query->getInt('page', 1)), 'limit' => min(100, max(1, $request->query->getInt('limit', $defaultLimit)))];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return array{items: list<T>, page: int, limit: int, total: int, pages: int}
     */
    public static function response(array $items, int $page, int $limit, int $total): array
    {
        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }
}
