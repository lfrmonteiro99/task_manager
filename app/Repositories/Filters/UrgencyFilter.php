<?php

declare(strict_types=1);

namespace App\Repositories\Filters;

class UrgencyFilter implements FilterStrategyInterface
{
    public function shouldApply(array $searchParams): bool
    {
        return !empty($searchParams['urgency']) ||
               (!empty($searchParams['overdue_only']) && $searchParams['overdue_only'] === '1');
    }

    public function apply(
        array &$conditions,
        array &$parameters,
        array $searchParams,
        bool $useFullTable = false
    ): void {
        if (!empty($searchParams['urgency'])) {
            $urgencyCondition = $this->buildUrgencyCondition($searchParams['urgency']);
            if ($urgencyCondition) {
                $conditions[] = $urgencyCondition;
            }
        }

        if (!empty($searchParams['overdue_only']) && $searchParams['overdue_only'] === '1') {
            // For overdue only, use direct date comparison
            $conditions[] = 'due_date < NOW() AND done = 0';
        }
    }

    private function buildUrgencyCondition(string $urgency): ?string
    {
        switch ($urgency) {
            case 'overdue':
                return 'due_date < NOW()';
            case 'due_soon':
                return 'due_date >= NOW() AND due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)';
            case 'due_this_week':
                return 'due_date > DATE_ADD(NOW(), INTERVAL 24 HOUR) AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)';
            case 'normal':
                return 'due_date > DATE_ADD(NOW(), INTERVAL 7 DAY)';
            default:
                return null;
        }
    }
}
