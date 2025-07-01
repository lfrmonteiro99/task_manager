<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class DateRangeFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return !empty($searchParams['due_date_from']) ||
               !empty($searchParams['due_date_to']) ||
               !empty($searchParams['created_from']) ||
               !empty($searchParams['created_to']);
    }

    public function apply(
        array &$conditions,
        array &$parameters,
        array $searchParams,
        bool $useFullTable = false
    ): void {
        // Due date range filters
        if (!empty($searchParams['due_date_from'])) {
            $conditions[] = 'due_date >= ?';
            $parameters[] = $searchParams['due_date_from'];
        }

        if (!empty($searchParams['due_date_to'])) {
            $conditions[] = 'due_date <= ?';
            $parameters[] = $searchParams['due_date_to'];
        }

        // Created date range filters
        if (!empty($searchParams['created_from'])) {
            $conditions[] = 'created_at >= ?';
            $parameters[] = $searchParams['created_from'];
        }

        if (!empty($searchParams['created_to'])) {
            $conditions[] = 'created_at <= ?';
            $parameters[] = $searchParams['created_to'];
        }
    }
}
