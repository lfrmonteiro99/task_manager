<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class FilterFactory
{
    /**
     * Create a filter chain based on context or configuration
     */
    public static function createForTaskSearch(): FilterChain
    {
        return FilterChain::createDefault();
    }

    /**
     * Create a minimal filter chain for basic searches
     */
    public static function createBasic(): FilterChain
    {
        return (new FilterChain())
            ->addFilter(new TextSearchFilter())
            ->addFilter(new StatusFilter())
            ->addFilter(new PriorityFilter());
    }

    /**
     * Create an advanced filter chain with all filters
     */
    public static function createAdvanced(): FilterChain
    {
        return FilterChain::createDefault();
    }

    /**
     * Create a custom filter chain based on user permissions or tier
     */
    public static function createForUserTier(string $userTier): FilterChain
    {
        $chain = new FilterChain();

        // Basic filters for all users
        $chain->addFilter(new TextSearchFilter())
              ->addFilter(new StatusFilter())
              ->addFilter(new PriorityFilter());

        // Advanced filters for premium users
        if (in_array($userTier, ['premium', 'enterprise', 'admin'])) {
            $chain->addFilter(new DateRangeFilter())
                  ->addFilter(new UrgencyFilter())
                  ->addFilter(new DoneStatusFilter());
        }

        return $chain;
    }
}
