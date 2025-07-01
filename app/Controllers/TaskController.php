<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Views\TaskView;
use App\Requests\TaskRequest;
use App\Services\TaskServiceInterface;
use App\Context\RequestContext;
use App\Enums\HttpStatusCode;
use InvalidArgumentException;
use Exception;

class TaskController extends BaseController
{
    public function __construct(
        private readonly TaskServiceInterface $taskService,
        private readonly RequestContext $requestContext,
        private readonly TaskView $taskView = new TaskView()
    ) {
    }

    private function getAuthenticatedUserId(): int
    {
        $userId = $this->requestContext->getUserId();
        if (!$userId) {
            throw new Exception('User not authenticated');
        }
        return $userId;
    }

    public function create(): void
    {
        $input = $this->getJsonInput();

        if (!$input) {
            $this->sendErrorResponse('Invalid JSON input');
            return;
        }

        // Validate using TaskRequest
        $taskRequest = new TaskRequest($input);

        if (!$taskRequest->validate()) {
            $this->sendValidationErrorResponse($taskRequest->getErrors());
            return;
        }

        try {
            $userId = $this->getAuthenticatedUserId();
            $validatedData = $taskRequest->getValidatedData();

            $success = $this->taskService->createTask($userId, $validatedData);

            if ($success) {
                $this->sendSuccessResponse('Task created successfully');
            } else {
                $this->sendErrorResponse('Failed to create task', HttpStatusCode::INTERNAL_SERVER_ERROR);
            }
        } catch (InvalidArgumentException $e) {
            $this->sendErrorResponse($e->getMessage(), HttpStatusCode::BAD_REQUEST);
        } catch (Exception $e) {
            $this->sendErrorResponse('Error creating task: ' . $e->getMessage(), HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function list(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // Service layer handles pagination logic and response formatting
            $result = $this->taskService->getTasks($userId, $_GET);
            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendErrorResponse(
                'Error retrieving tasks: ' . $e->getMessage(),
                HttpStatusCode::INTERNAL_SERVER_ERROR
            );
        }
    }

    public function markDone(int $id = 0): void
    {
        // If no ID passed as parameter, check for it in the request
        if ($id === 0) {
            if (isset($_GET['id'])) {
                $id = (int) $_GET['id'];
            } else {
                $input = $this->getJsonInput();
                if (!$input || !$this->validateRequiredFields($input, ['id'])) {
                    $this->sendErrorResponse('Missing required field: id');
                    return;
                }
                $id = (int) $input['id'];
            }
        }

        try {
            $userId = $this->getAuthenticatedUserId();
            $success = $this->taskService->markTaskAsDone($id, $userId);

            if ($success) {
                $this->sendSuccessResponse('Task marked as done');
            } else {
                $this->sendErrorResponse('Failed to mark task as done', HttpStatusCode::INTERNAL_SERVER_ERROR);
            }
        } catch (InvalidArgumentException $e) {
            $this->sendErrorResponse($e->getMessage(), HttpStatusCode::BAD_REQUEST);
        } catch (Exception $e) {
            $this->sendErrorResponse('Error updating task: ' . $e->getMessage(), HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function delete(int $id = 0): void
    {
        // If no ID passed as parameter, check for it in the request
        if ($id === 0) {
            if (isset($_GET['id'])) {
                $id = (int) $_GET['id'];
            } else {
                $input = $this->getJsonInput();
                if (!$input || !$this->validateRequiredFields($input, ['id'])) {
                    $this->sendErrorResponse('Missing required field: id');
                    return;
                }
                $id = (int) $input['id'];
            }
        }

        try {
            $userId = $this->getAuthenticatedUserId();
            $success = $this->taskService->deleteTask($id, $userId);

            if ($success) {
                $this->sendSuccessResponse('Task deleted successfully');
            } else {
                $this->sendErrorResponse('Failed to delete task', HttpStatusCode::INTERNAL_SERVER_ERROR);
            }
        } catch (InvalidArgumentException $e) {
            $this->sendErrorResponse($e->getMessage(), HttpStatusCode::BAD_REQUEST);
        } catch (Exception $e) {
            $this->sendErrorResponse('Error deleting task: ' . $e->getMessage(), HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function overdue(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $tasks = $this->taskService->getOverdueTasks($userId);
            $this->sendJsonResponse($this->taskView->formatOverdueTaskList($tasks));
        } catch (Exception $e) {
            $this->sendErrorResponse(
                'Error retrieving overdue tasks: ' . $e->getMessage(),
                HttpStatusCode::INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(int $id): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $task = $this->taskService->getTaskById($id, $userId);

            if ($task) {
                $this->sendJsonResponse($this->taskView->formatSingleTask($task));
            } else {
                $this->sendErrorResponse('Task not found', HttpStatusCode::NOT_FOUND);
            }
        } catch (InvalidArgumentException $e) {
            $this->sendErrorResponse($e->getMessage(), HttpStatusCode::BAD_REQUEST);
        } catch (Exception $e) {
            $this->sendErrorResponse(
                'Error retrieving task: ' . $e->getMessage(),
                HttpStatusCode::INTERNAL_SERVER_ERROR
            );
        }
    }

    public function task(): void
    {
        if (!isset($_GET['id'])) {
            $this->sendErrorResponse('Missing required parameter: id');
            return;
        }

        $id = (int) $_GET['id'];
        $this->show($id);
    }

    public function update(int $id = 0): void
    {
        // If no ID passed as parameter, check for it in the request
        if ($id === 0) {
            if (isset($_GET['id'])) {
                $id = (int) $_GET['id'];
            } else {
                $this->sendErrorResponse('Missing required parameter: id');
                return;
            }
        }

        $input = $this->getJsonInput();

        if (!$input) {
            $this->sendErrorResponse('Invalid JSON input');
            return;
        }

        // Validate using TaskRequest
        $taskRequest = new TaskRequest($input);

        if (!$taskRequest->validate()) {
            $this->sendValidationErrorResponse($taskRequest->getErrors());
            return;
        }

        try {
            $userId = $this->getAuthenticatedUserId();
            $validatedData = $taskRequest->getValidatedData();

            $success = $this->taskService->updateTask($id, $userId, $validatedData);

            if ($success) {
                $this->sendSuccessResponse('Task updated successfully');
            } else {
                $this->sendErrorResponse('Task not found or failed to update', HttpStatusCode::NOT_FOUND);
            }
        } catch (InvalidArgumentException $e) {
            $this->sendErrorResponse($e->getMessage(), HttpStatusCode::BAD_REQUEST);
        } catch (Exception $e) {
            $this->sendErrorResponse('Error updating task: ' . $e->getMessage(), HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function statistics(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $stats = $this->taskService->getTaskStatistics($userId);
            $this->sendJsonResponse(['statistics' => $stats]);
        } catch (Exception $e) {
            $this->sendErrorResponse(
                'Error retrieving statistics: ' . $e->getMessage(),
                HttpStatusCode::INTERNAL_SERVER_ERROR
            );
        }
    }
}
