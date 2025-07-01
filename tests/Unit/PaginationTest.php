<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\PaginationService;
use App\Services\PaginationServiceInterface;

class PaginationTest extends TestCase
{
    private PaginationServiceInterface $paginationService;

    protected function setUp(): void
    {
        $this->paginationService = new PaginationService();
    }

    public function testGetPaginationParamsWithDefaults(): void
    {
        $params = $this->paginationService->getPaginationParams([]);
        
        $this->assertEquals(1, $params['page']);
        $this->assertEquals(10, $params['limit']);
        $this->assertEquals(0, $params['offset']);
    }

    public function testGetPaginationParamsWithCustomValues(): void
    {
        $queryParams = ['page' => '2', 'limit' => '20'];
        $params = $this->paginationService->getPaginationParams($queryParams);
        
        $this->assertEquals(2, $params['page']);
        $this->assertEquals(20, $params['limit']);
        $this->assertEquals(20, $params['offset']);
    }

    public function testGetPaginationParamsWithInvalidValues(): void
    {
        $queryParams = ['page' => '0', 'limit' => '1000'];
        $params = $this->paginationService->getPaginationParams($queryParams);
        
        $this->assertEquals(1, $params['page']); // Minimum page is 1
        $this->assertEquals(10, $params['limit']); // Over max limit, default to 10
        $this->assertEquals(0, $params['offset']);
    }

    public function testCreatePaginator(): void
    {
        $paginator = $this->paginationService->createPaginator(50, 10, 3);
        
        $this->assertEquals(50, $paginator->getTotalItems());
        $this->assertEquals(10, $paginator->getItemsPerPage());
        $this->assertEquals(3, $paginator->getCurrentPage());
        $this->assertEquals(5, $paginator->getNumPages());
        $this->assertNotNull($paginator->getPrevPage());
        $this->assertNotNull($paginator->getNextPage());
    }

    public function testFormatPaginationResponse(): void
    {
        $data = ['task1', 'task2', 'task3'];
        $paginator = $this->paginationService->createPaginator(25, 10, 2);
        
        $response = $this->paginationService->formatPaginationResponse($data, $paginator);
        
        $this->assertEquals($data, $response['data']);
        $this->assertEquals(2, $response['pagination']['current_page']);
        $this->assertEquals(10, $response['pagination']['per_page']);
        $this->assertEquals(25, $response['pagination']['total_items']);
        $this->assertEquals(3, $response['pagination']['total_pages']);
        $this->assertTrue($response['pagination']['has_previous']);
        $this->assertTrue($response['pagination']['has_next']);
        $this->assertEquals(1, $response['pagination']['previous_page']);
        $this->assertEquals(3, $response['pagination']['next_page']);
    }

    public function testFormatPaginationResponseFirstPage(): void
    {
        $data = ['task1', 'task2'];
        $paginator = $this->paginationService->createPaginator(25, 10, 1);
        
        $response = $this->paginationService->formatPaginationResponse($data, $paginator);
        
        $this->assertFalse($response['pagination']['has_previous']);
        $this->assertTrue($response['pagination']['has_next']);
        $this->assertNull($response['pagination']['previous_page']);
        $this->assertEquals(2, $response['pagination']['next_page']);
    }

    public function testFormatPaginationResponseLastPage(): void
    {
        $data = ['task1', 'task2', 'task3', 'task4', 'task5'];
        $paginator = $this->paginationService->createPaginator(25, 10, 3);
        
        $response = $this->paginationService->formatPaginationResponse($data, $paginator);
        
        $this->assertTrue($response['pagination']['has_previous']);
        $this->assertFalse($response['pagination']['has_next']);
        $this->assertEquals(2, $response['pagination']['previous_page']);
        $this->assertNull($response['pagination']['next_page']);
    }
}