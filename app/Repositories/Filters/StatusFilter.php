<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class StatusFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return !empty($searchParams['status']);
    }

    public function apply(
        array &$conditions,
        array &$parameters,
        array $searchParams,
        bool $useFullTable = false
    ): void {
        if (!$this->shouldApply($searchParams)) {
            return;
        }

        if ($useFullTable) {
            // When using full table, map status to database fields
            if ($searchParams['status'] === 'completed') {
                $conditions[] = 'done = 1';
            } elseif ($searchParams['status'] === 'pending') {
                $conditions[] = 'done = 0 AND status IN (\'pending\', \'overdue\')';
            }
        } else {
            $conditions[] = 'status = ?';
            $parameters[] = $searchParams['status'];
        }
    }
}
