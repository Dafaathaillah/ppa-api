<?php

namespace App\Queries;

use App\Models\UserAll;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserAllTableQuery
{
    private const SORTABLE_COLUMNS = [
        'nrp',
        'username',
        'department',
        'position',
        'email',
        'updated_at',
    ];

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'updated_at');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc'));
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) ($filters['page'] ?? 1));

        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $sort = 'updated_at';
            $direction = 'desc';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 10;
        }

        return UserAll::query()
            ->select([
                'id',
                'nrp',
                'username',
                'department',
                'position',
                'email',
                'updated_at',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    foreach (['nrp', 'username', 'department', 'position', 'email'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}