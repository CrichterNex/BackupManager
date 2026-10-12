<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Pagination in the API's shape: page, limit (max 1000), total_count, total_pages. */
final class Page
{
    public static function params(Request $request): array
    {
        $data = validator($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ])->validate();
        $page = (int) ($data['page'] ?? 1);
        $limit = (int) ($data['limit'] ?? 50);

        return [$page, $limit, ($page - 1) * $limit];
    }

    /** @return array{0: Collection, 1: array} */
    public static function run(Request $request, EloquentBuilder|Builder $query): array
    {
        [$page, $limit, $offset] = self::params($request);
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->offset($offset)->limit($limit)->get();

        return [$rows, [
            'page' => $page,
            'limit' => $limit,
            'total_count' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ]];
    }

    /** Whitelisted ORDER BY so sort input never reaches SQL directly. */
    public static function order(Request $request, array $allowed, string $fallback): array
    {
        $key = $request->query('sort_by');
        $column = (is_string($key) && isset($allowed[$key])) ? $allowed[$key] : $allowed[$fallback];

        return [$column, $request->query('sort_order') === 'asc' ? 'asc' : 'desc'];
    }
}
