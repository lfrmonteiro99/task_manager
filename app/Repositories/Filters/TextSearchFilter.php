<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class TextSearchFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return !empty($searchParams['search']);
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

        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $searchTerm = '%' . $searchParams['search'] . '%';
        $parameters[] = $searchTerm;
        $parameters[] = $searchTerm;
    }
}
