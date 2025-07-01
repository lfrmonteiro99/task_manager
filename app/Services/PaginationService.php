<?php

declare(strict_types=1);

namespace App\Services;

use JasonGrimes\Paginator;
use InvalidArgumentException;

class PaginationService
{
    private const DEFAULT_PER_PAGE = 10;
    private const MAX_PER_PAGE = 100;
    private const MIN_PER_PAGE = 1;

    /**
     * Create paginator instance
     */
    public function createPaginator(
        int $totalItems,
        int $itemsPerPage,
        int $currentPage,
        string $urlPattern = ''
    ): Paginator {
        // Validate parameters
        $itemsPerPage = $this->validateItemsPerPage($itemsPerPage);
        $currentPage = max(1, $currentPage);

        return new Paginator($totalItems, $itemsPerPage, $currentPage, $urlPattern);
    }

    /**
     * Get pagination parameters from request
     * @param array<string, string|int> $queryParams
     * @return array{page: int, limit: int, offset: int}
     */
    public function getPaginationParams(array $queryParams = []): array
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = $this->validateItemsPerPage((int) ($queryParams['limit'] ?? self::DEFAULT_PER_PAGE));
        $offset = ($page - 1) * $limit;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Format pagination response
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    public function formatPaginationResponse(array $data, Paginator $paginator): array
    {
        $previousPage = $paginator->getPrevPage();
        $nextPage = $paginator->getNextPage();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->getCurrentPage(),
                'per_page' => $paginator->getItemsPerPage(),
                'total_items' => $paginator->getTotalItems(),
                'total_pages' => $paginator->getNumPages(),
                'has_previous' => $previousPage !== null,
                'has_next' => $nextPage !== null,
                'previous_page' => $previousPage,
                'next_page' => $nextPage,
            ]
        ];
    }

    /**
     * Validate items per page parameter
     */
    private function validateItemsPerPage(int $itemsPerPage): int
    {
        if ($itemsPerPage < self::MIN_PER_PAGE || $itemsPerPage > self::MAX_PER_PAGE) {
            return self::DEFAULT_PER_PAGE;
        }
        return $itemsPerPage;
    }
}
