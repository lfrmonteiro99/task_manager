<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class PriorityFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return !empty($searchParams['priority']);
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

        $conditions[] = 'priority = ?';
        $parameters[] = $searchParams['priority'];
    }
}
