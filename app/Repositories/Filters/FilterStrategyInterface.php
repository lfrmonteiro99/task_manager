<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

interface FilterStrategyInterface
{
    /**
     * Apply the filter to the query conditions
     *
     * @param array<string> $conditions
     * @param array<mixed> $parameters
     * @param array<string, mixed> $searchParams
     * @param bool $useFullTable
     */
    public function apply(
        array &$conditions,
        array &$parameters,
        array $searchParams,
        bool $useFullTable = false
    ): void;

    /**
     * Check if this filter should be applied based on search parameters
     *
     * @param array<string, mixed> $searchParams
     */
    public function shouldApply(array $searchParams): bool;
}
