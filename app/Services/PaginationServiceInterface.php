<?php

declare(strict_types=1);

namespace App\Services;

use JasonGrimes\Paginator;

interface PaginationServiceInterface
{
    /**
     * Create paginator instance
     */
    public function createPaginator(
        int $totalItems,
        int $itemsPerPage,
        int $currentPage,
        string $urlPattern = ''
    ): Paginator;

    /**
     * Get pagination parameters from request
     * @param array<string, string|int> $queryParams
     * @return array{page: int, limit: int, offset: int}
     */
    public function getPaginationParams(array $queryParams = []): array;

    /**
     * Format pagination response
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function formatPaginationResponse(array $data, Paginator $paginator): array;
}
