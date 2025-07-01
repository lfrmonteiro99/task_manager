<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\HttpStatusCode;

abstract class BaseController
{
    /**
     * @param array<string, mixed> $data
     */
    protected function sendJsonResponse(array $data, HttpStatusCode $statusCode = HttpStatusCode::OK): void
    {
        http_response_code($statusCode->value);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    protected function sendErrorResponse(
        string $message,
        HttpStatusCode $statusCode = HttpStatusCode::BAD_REQUEST
    ): void {
        $this->sendJsonResponse(['error' => $message], $statusCode);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function sendSuccessResponse(string $message, array $data = []): void
    {
        $response = ['success' => true, 'message' => $message];
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        $this->sendJsonResponse($response);
    }

    /**
     * @param array<string, string> $errors
     */
    protected function sendValidationErrorResponse(array $errors): void
    {
        $this->sendJsonResponse([
            'error' => 'Validation failed',
            'errors' => $errors
        ], HttpStatusCode::UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        if ($input === false || empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string> $requiredFields
     */
    protected function validateRequiredFields(array $data, array $requiredFields): bool
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
}
