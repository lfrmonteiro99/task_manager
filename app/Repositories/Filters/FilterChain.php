<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class FilterChain
{
    /** @var FilterStrategyInterface[] */
    private array $filters = [];

    public function addFilter(FilterStrategyInterface $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * Apply all filters in the chain
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
    ): void {
        foreach ($this->filters as $filter) {
            if ($filter->shouldApply($searchParams)) {
                $filter->apply($conditions, $parameters, $searchParams, $useFullTable);
            }
        }
    }

    /**
     * Create a default filter chain with all available filters
     */
    public static function createDefault(): self
    {
        return (new self())
            ->addFilter(new TextSearchFilter())
            ->addFilter(new StatusFilter())
            ->addFilter(new PriorityFilter())
            ->addFilter(new DoneStatusFilter())
            ->addFilter(new DateRangeFilter())
            ->addFilter(new UrgencyFilter());
    }
}
