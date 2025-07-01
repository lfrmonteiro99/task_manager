<?php

declare(strict_types=1);

namespace App\Requests;

use App\Utils\InputSanitizer;

class TaskRequest extends BaseRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:50',
            'description' => 'required|string|min:10|max:1000',
            'due_date' => 'required|date|after:now|before:+5 years'
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'The title field is required.',
            'title.min' => 'The title must be at least 3 characters.',
            'title.max' => 'The title may not be greater than 50 characters.',
            'description.required' => 'The description field is required.',
            'description.min' => 'The description must be at least 10 characters.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            'due_date.required' => 'The due date field is required.',
            'due_date.date' => 'The due date must be a valid date in YYYY-MM-DD HH:MM:SS format.',
            'due_date.after' => 'The due date must be in the future.',
            'due_date.before' => 'The due date may not be more than 5 years in the future.'
        ];
    }

    /**
     * Sanitize input data before validation
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function sanitizeInput(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                $sanitized[$key] = $value;
                continue;
            }

            // Validate for malicious content first
            try {
                InputSanitizer::validateSafeInput($value, $key);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("Security violation in field '{$key}': " . $e->getMessage());
            }

            // Sanitize based on field type
            switch ($key) {
                case 'title':
                    $sanitized[$key] = InputSanitizer::sanitizeString($value, 255);
                    break;
                case 'description':
                    $sanitized[$key] = InputSanitizer::sanitizeString($value, 2000);
                    break;
                case 'due_date':
                    $sanitized[$key] = InputSanitizer::sanitizeDateTime($value);
                    if ($sanitized[$key] === null) {
                        throw new \InvalidArgumentException("Invalid date format for field '{$key}'");
                    }
                    break;
                case 'priority':
                    $priority = strtolower(trim($value));
                    if (in_array($priority, ['low', 'medium', 'high'], true)) {
                        $sanitized[$key] = $priority;
                    } else {
                        throw new \InvalidArgumentException("Invalid priority value");
                    }
                    break;
                default:
                    $sanitized[$key] = InputSanitizer::sanitizeString($value);
            }
        }

        return $sanitized;
    }
}
