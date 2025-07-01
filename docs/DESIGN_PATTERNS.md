# Design Patterns Implementation

## Query Filter System Refactoring

The task repository filtering system has been refactored to use multiple design patterns, replacing the original monolithic `buildWhereClause` method with a more maintainable and extensible architecture.

## Design Patterns Used

### 1. **Strategy Pattern**

Each filter type is implemented as a separate strategy class implementing the `FilterStrategyInterface`:

```php
interface FilterStrategyInterface
{
    public function apply(array &$conditions, array &$parameters, array $searchParams, bool $useFullTable = false): void;
    public function shouldApply(array $searchParams): bool;
}
```

**Benefits:**
- **Single Responsibility**: Each filter handles one specific type of filtering
- **Open/Closed Principle**: Easy to add new filters without modifying existing code
- **Testability**: Each filter can be unit tested independently

**Filter Strategies Implemented:**
- `TextSearchFilter` - Handles title/description text search
- `StatusFilter` - Handles task status filtering (pending, completed, etc.)
- `PriorityFilter` - Handles priority-based filtering
- `DateRangeFilter` - Handles due date and creation date ranges
- `UrgencyFilter` - Handles urgency status and overdue filtering
- `DoneStatusFilter` - Handles boolean done status filtering

### 2. **Chain of Responsibility Pattern**

The `FilterChain` class manages multiple filters and applies them sequentially:

```php
class FilterChain
{
    private array $filters = [];
    
    public function addFilter(FilterStrategyInterface $filter): self
    public function apply(array &$conditions, array &$parameters, array $searchParams, bool $useFullTable = false): void
}
```

**Benefits:**
- **Flexible Composition**: Filters can be combined in any order
- **Dynamic Configuration**: Different filter chains for different contexts
- **Separation of Concerns**: Chain management is separate from individual filter logic

### 3. **Factory Pattern**

The `FilterFactory` provides different pre-configured filter chains:

```php
class FilterFactory
{
    public static function createForTaskSearch(): FilterChain
    public static function createBasic(): FilterChain
    public static function createAdvanced(): FilterChain
    public static function createForUserTier(string $userTier): FilterChain
}
```

**Benefits:**
- **Centralized Configuration**: Single place to define filter combinations
- **User Tier Support**: Different filter sets based on user permissions
- **Easy Maintenance**: Change filter combinations without touching business logic

## Before vs After

### Before (Monolithic Approach)
```php
private function buildWhereClause(int $userId, array $searchParams, bool $useFullTable): array
{
    $conditions = ['user_id = ?'];
    $parameters = [$userId];

    $this->addTextSearchCondition($conditions, $parameters, $searchParams);
    $this->addStatusCondition($conditions, $parameters, $searchParams, $useFullTable);
    $this->addPriorityCondition($conditions, $parameters, $searchParams);
    $this->addDoneStatusCondition($conditions, $parameters, $searchParams);
    $this->addDateRangeConditions($conditions, $parameters, $searchParams);
    $this->addUrgencyConditions($conditions, $parameters, $searchParams);

    return ['clause' => implode(' AND ', $conditions), 'params' => $parameters];
}
```

### After (Design Pattern Approach)
```php
private function buildWhereClause(int $userId, array $searchParams, bool $useFullTable): array
{
    $conditions = ['user_id = ?'];
    $parameters = [$userId];

    // Use Strategy + Chain of Responsibility patterns
    $filterChain = FilterFactory::createForTaskSearch();
    $filterChain->apply($conditions, $parameters, $searchParams, $useFullTable);

    return ['clause' => implode(' AND ', $conditions), 'params' => $parameters];
}
```

## Key Improvements

### 1. **Maintainability**
- **Single Responsibility**: Each filter class has one reason to change
- **Isolated Logic**: Filter logic is contained within individual classes
- **Clear Dependencies**: Easy to understand what each filter does

### 2. **Extensibility**
- **Add New Filters**: Create new strategy classes without modifying existing code
- **Combine Filters**: Use factory methods to create different filter combinations
- **User-Specific Filtering**: Different filter sets based on user tier/permissions

### 3. **Testability**
- **Unit Testing**: Each filter strategy can be tested independently
- **Integration Testing**: Filter chains can be tested with known combinations
- **Mock Support**: Easy to mock individual filters for testing

### 4. **Flexibility**
- **Runtime Configuration**: Filter chains can be built dynamically
- **Conditional Logic**: Filters only apply when conditions are met
- **Multiple Contexts**: Different filter chains for different use cases

## Usage Examples

### Basic Usage
```php
// Default filter chain for task search
$filterChain = FilterFactory::createForTaskSearch();
$filterChain->apply($conditions, $parameters, $searchParams, $useFullTable);
```

### User Tier Based Filtering
```php
// Different filters based on user subscription tier
$filterChain = FilterFactory::createForUserTier($user->getTier());
$filterChain->apply($conditions, $parameters, $searchParams, $useFullTable);
```

### Custom Filter Chain
```php
// Build custom filter chain for specific requirements
$filterChain = (new FilterChain())
    ->addFilter(new TextSearchFilter())
    ->addFilter(new PriorityFilter())
    ->addFilter(new DateRangeFilter());
```

## Performance Considerations

- **Lazy Evaluation**: Filters only execute if `shouldApply()` returns true
- **Early Exit**: Individual filters can return early if conditions aren't met
- **Memory Efficiency**: Filter objects are lightweight and reusable
- **Database Optimization**: Same SQL generation as before, just better organized

## Future Enhancements

1. **Configuration-Driven Filters**: Load filter chains from configuration files
2. **Cache-Aware Filters**: Filters that consider caching strategies
3. **Validation Filters**: Input validation as part of the filter chain
4. **Audit Filters**: Automatic logging of filter usage for analytics
5. **Dynamic Sorting**: Integrate sorting logic into the pattern system

This refactoring maintains 100% backward compatibility while providing a much more maintainable and extensible foundation for future filter development.