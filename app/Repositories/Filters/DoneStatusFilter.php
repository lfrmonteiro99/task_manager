<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class DoneStatusFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return isset($searchParams['done']) && $searchParams['done'] !== '';
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

        $conditions[] = 'status = ?';
        $parameters[] = $searchParams['done'] === '1' || $searchParams['done'] === 'true'
            ? 'completed'
            : 'pending';
    }
}
