<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Repositories\Filters\TextSearchFilter;
use App\Repositories\Filters\StatusFilter;
use App\Repositories\Filters\PriorityFilter;
use App\Repositories\Filters\DateRangeFilter;
use App\Repositories\Filters\UrgencyFilter;
use App\Repositories\Filters\DoneStatusFilter;
use App\Repositories\Filters\FilterChain;
use App\Repositories\Filters\FilterFactory;

class FilterStrategyUnitTest extends TestCase
{
    public function testTextSearchFilterShouldApply(): void
    {
        $filter = new TextSearchFilter();
        
        $this->assertTrue($filter->shouldApply(['search' => 'test']));
        $this->assertFalse($filter->shouldApply([]));
        $this->assertFalse($filter->shouldApply(['search' => '']));
    }

    public function testTextSearchFilterApply(): void
    {
        $filter = new TextSearchFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['search' => 'meeting'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('(title LIKE ? OR description LIKE ?)', $conditions[0]);
        $this->assertEquals(['%meeting%', '%meeting%'], $parameters);
    }

    public function testStatusFilterShouldApply(): void
    {
        $filter = new StatusFilter();
        
        $this->assertTrue($filter->shouldApply(['status' => 'pending']));
        $this->assertFalse($filter->shouldApply([]));
        $this->assertFalse($filter->shouldApply(['status' => '']));
    }

    public function testStatusFilterApplyWithView(): void
    {
        $filter = new StatusFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['status' => 'pending'];

        $filter->apply($conditions, $parameters, $searchParams, false);

        $this->assertCount(1, $conditions);
        $this->assertEquals('status = ?', $conditions[0]);
        $this->assertEquals(['pending'], $parameters);
    }

    public function testStatusFilterApplyWithFullTable(): void
    {
        $filter = new StatusFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['status' => 'completed'];

        $filter->apply($conditions, $parameters, $searchParams, true);

        $this->assertCount(1, $conditions);
        $this->assertEquals('done = 1', $conditions[0]);
        $this->assertEmpty($parameters);
    }

    public function testPriorityFilterShouldApply(): void
    {
        $filter = new PriorityFilter();
        
        $this->assertTrue($filter->shouldApply(['priority' => 'high']));
        $this->assertFalse($filter->shouldApply([]));
        $this->assertFalse($filter->shouldApply(['priority' => '']));
    }

    public function testPriorityFilterApply(): void
    {
        $filter = new PriorityFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['priority' => 'urgent'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('priority = ?', $conditions[0]);
        $this->assertEquals(['urgent'], $parameters);
    }

    public function testDateRangeFilterShouldApply(): void
    {
        $filter = new DateRangeFilter();
        
        $this->assertTrue($filter->shouldApply(['due_date_from' => '2025-01-01']));
        $this->assertTrue($filter->shouldApply(['due_date_to' => '2025-12-31']));
        $this->assertTrue($filter->shouldApply(['created_from' => '2024-01-01']));
        $this->assertTrue($filter->shouldApply(['created_to' => '2024-12-31']));
        $this->assertFalse($filter->shouldApply([]));
    }

    public function testDateRangeFilterApply(): void
    {
        $filter = new DateRangeFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'due_date_from' => '2025-01-01',
            'due_date_to' => '2025-12-31',
            'created_from' => '2024-01-01',
            'created_to' => '2024-12-31'
        ];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(4, $conditions);
        $this->assertEquals('due_date >= ?', $conditions[0]);
        $this->assertEquals('due_date <= ?', $conditions[1]);
        $this->assertEquals('created_at >= ?', $conditions[2]);
        $this->assertEquals('created_at <= ?', $conditions[3]);
        $this->assertEquals(['2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'], $parameters);
    }

    public function testUrgencyFilterShouldApply(): void
    {
        $filter = new UrgencyFilter();
        
        $this->assertTrue($filter->shouldApply(['urgency' => 'overdue']));
        $this->assertTrue($filter->shouldApply(['overdue_only' => '1']));
        $this->assertFalse($filter->shouldApply([]));
        $this->assertFalse($filter->shouldApply(['overdue_only' => '0']));
    }

    public function testUrgencyFilterApply(): void
    {
        $filter = new UrgencyFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['urgency' => 'due_soon'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('due_date >= NOW() AND due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)', $conditions[0]);
        $this->assertEquals([], $parameters);
    }

    public function testUrgencyFilterApplyOverdueOnly(): void
    {
        $filter = new UrgencyFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['overdue_only' => '1'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('due_date < NOW() AND done = 0', $conditions[0]);
        $this->assertEquals([], $parameters);
    }

    public function testDoneStatusFilterShouldApply(): void
    {
        $filter = new DoneStatusFilter();
        
        $this->assertTrue($filter->shouldApply(['done' => '1']));
        $this->assertTrue($filter->shouldApply(['done' => '0']));
        $this->assertTrue($filter->shouldApply(['done' => 'true']));
        $this->assertTrue($filter->shouldApply(['done' => 'false']));
        $this->assertFalse($filter->shouldApply([]));
        $this->assertFalse($filter->shouldApply(['done' => '']));
    }

    public function testDoneStatusFilterApplyCompleted(): void
    {
        $filter = new DoneStatusFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['done' => '1'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('status = ?', $conditions[0]);
        $this->assertEquals(['completed'], $parameters);
    }

    public function testDoneStatusFilterApplyPending(): void
    {
        $filter = new DoneStatusFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = ['done' => '0'];

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions);
        $this->assertEquals('status = ?', $conditions[0]);
        $this->assertEquals(['pending'], $parameters);
    }

    public function testFilterChainAddFilter(): void
    {
        $chain = new FilterChain();
        $textFilter = new TextSearchFilter();
        
        $result = $chain->addFilter($textFilter);
        
        $this->assertSame($chain, $result); // Test fluent interface
    }

    public function testFilterChainApplyMultipleFilters(): void
    {
        $chain = new FilterChain();
        $chain->addFilter(new TextSearchFilter())
              ->addFilter(new StatusFilter())
              ->addFilter(new PriorityFilter());

        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'meeting',
            'status' => 'pending',
            'priority' => 'high'
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertCount(3, $conditions);
        $this->assertEquals('(title LIKE ? OR description LIKE ?)', $conditions[0]);
        $this->assertEquals('status = ?', $conditions[1]);
        $this->assertEquals('priority = ?', $conditions[2]);
        $this->assertEquals(['%meeting%', '%meeting%', 'pending', 'high'], $parameters);
    }

    public function testFilterChainApplyOnlyApplicableFilters(): void
    {
        $chain = new FilterChain();
        $chain->addFilter(new TextSearchFilter())
              ->addFilter(new StatusFilter())
              ->addFilter(new PriorityFilter());

        $conditions = [];
        $parameters = [];
        $searchParams = ['search' => 'meeting']; // Only search param provided

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertCount(1, $conditions); // Only TextSearchFilter should apply
        $this->assertEquals('(title LIKE ? OR description LIKE ?)', $conditions[0]);
        $this->assertEquals(['%meeting%', '%meeting%'], $parameters);
    }

    public function testFilterChainCreateDefault(): void
    {
        $chain = FilterChain::createDefault();
        
        $this->assertInstanceOf(FilterChain::class, $chain);
        
        // Test that all filters are included by applying with all params
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'test',
            'status' => 'pending',
            'priority' => 'high',
            'done' => '0',
            'due_date_from' => '2025-01-01',
            'urgency' => 'overdue'
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertGreaterThan(0, count($conditions));
    }

    public function testFilterFactoryCreateForTaskSearch(): void
    {
        $chain = FilterFactory::createForTaskSearch();
        
        $this->assertInstanceOf(FilterChain::class, $chain);
    }

    public function testFilterFactoryCreateBasic(): void
    {
        $chain = FilterFactory::createBasic();
        
        $this->assertInstanceOf(FilterChain::class, $chain);
        
        // Test that basic filters work
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'test',
            'status' => 'pending',
            'priority' => 'high'
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertCount(3, $conditions);
    }

    public function testFilterFactoryCreateForUserTierBasic(): void
    {
        $chain = FilterFactory::createForUserTier('basic');
        
        $this->assertInstanceOf(FilterChain::class, $chain);
        
        // Test basic tier has limited filters
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'test',
            'status' => 'pending',
            'priority' => 'high',
            'due_date_from' => '2025-01-01', // Should be ignored for basic tier
            'urgency' => 'overdue' // Should be ignored for basic tier
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertEquals(3, count($conditions)); // Only basic filters
    }

    public function testFilterFactoryCreateForUserTierPremium(): void
    {
        $chain = FilterFactory::createForUserTier('premium');
        
        $this->assertInstanceOf(FilterChain::class, $chain);
        
        // Test premium tier has all filters
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'test',
            'status' => 'pending',
            'priority' => 'high',
            'due_date_from' => '2025-01-01',
            'urgency' => 'overdue',
            'done' => '0'
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        $this->assertGreaterThan(3, count($conditions)); // Should have advanced filters too
    }

    public function testFilterFactoryCreateAdvanced(): void
    {
        $chain = FilterFactory::createAdvanced();
        
        $this->assertInstanceOf(FilterChain::class, $chain);
    }

    public function testFilterDoesNotApplyWhenConditionsNotMet(): void
    {
        $filter = new TextSearchFilter();
        $conditions = [];
        $parameters = [];
        $searchParams = []; // No search parameter

        $filter->apply($conditions, $parameters, $searchParams);

        $this->assertEmpty($conditions);
        $this->assertEmpty($parameters);
    }

    public function testMultipleFiltersWithMixedConditions(): void
    {
        $chain = FilterChain::createDefault();
        $conditions = [];
        $parameters = [];
        $searchParams = [
            'search' => 'important',
            'priority' => 'high',
            // status not provided - should be skipped
            'due_date_from' => '2025-06-01'
            // urgency not provided - should be skipped
        ];

        $chain->apply($conditions, $parameters, $searchParams);

        // Should only apply filters where conditions are met
        $this->assertGreaterThan(0, count($conditions));
        $this->assertContains('(title LIKE ? OR description LIKE ?)', $conditions);
        $this->assertContains('priority = ?', $conditions);
        $this->assertContains('due_date >= ?', $conditions);
        
        // Check parameters match applied filters
        $this->assertContains('%important%', $parameters);
        $this->assertContains('high', $parameters);
        $this->assertContains('2025-06-01', $parameters);
    }
}