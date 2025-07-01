<?php

declare(strict_types=1);

namespace App\Utils;

class InputSanitizer
{
    /**
     * Sanitize string input to prevent XSS attacks
     */
    public static function sanitizeString(string $input, int $maxLength = 1000): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Trim whitespace
        $input = trim($input);

        // Limit length
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }

        // Convert special characters to HTML entities
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $input;
    }

    /**
     * Sanitize and validate email addresses
     */
    public static function sanitizeEmail(string $email): ?string
    {
        $email = trim($email);
        $sanitizedEmail = filter_var($email, FILTER_SANITIZE_EMAIL);

        if ($sanitizedEmail === false || filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $sanitizedEmail;
    }

    /**
     * Sanitize integer input with range validation
     */
    public static function sanitizeInteger(mixed $input, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $value = filter_var($input, FILTER_VALIDATE_INT);

        if ($value === false) {
            return null;
        }

        if ($value < $min || $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * Sanitize date/time input
     */
    public static function sanitizeDateTime(string $input): ?string
    {
        $input = trim($input);

        // Remove any non-date characters except spaces, hyphens, colons
        $input = preg_replace('/[^0-9\-: ]/', '', $input);

        if (empty($input)) {
            return null;
        }

        // Validate date format
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $input);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $input) {
            return null;
        }

        return $input;
    }

    /**
     * Sanitize and validate URL
     */
    public static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        $sanitizedUrl = filter_var($url, FILTER_SANITIZE_URL);

        if ($sanitizedUrl === false || filter_var($sanitizedUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Only allow HTTP/HTTPS protocols
        if (!preg_match('/^https?:\/\//', $sanitizedUrl)) {
            return null;
        }

        return $sanitizedUrl;
    }

    /**
     * Remove potential SQL injection patterns
     */
    public static function detectSqlInjection(string $input): bool
    {
        $patterns = [
            // Common SQL injection keywords
            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute|sp_|xp_)\b/i',
            // SQL comments (more specific patterns to avoid false positives)
            '/--\s|\/\*|\*\//',
            // String concatenation
            '/\|\||concat\s*\(/i',
            // Hexadecimal values
            '/0x[0-9a-f]+/i',
            // WAITFOR DELAY (time-based injection)
            '/waitfor\s+delay/i',
            // Boolean-based blind injection
            '/\b(and|or)\s+\d+\s*=\s*\d+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove potential XSS patterns
     */
    public static function detectXss(string $input): bool
    {
        $patterns = [
            // Script tags
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i',
            // JavaScript in attributes
            '/on\w+\s*=\s*["\'][^"\']*["\']/',
            // JavaScript protocol
            '/javascript:/i',
            // Data URLs with JavaScript
            '/data:.*script/i',
            // Style tags with expressions
            '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i',
            // CSS expressions
            '/expression\s*\(/i',
            // Import statements
            '/@import/i',
            // iframe, object, embed tags
            '/<(iframe|object|embed|form)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprehensive input sanitization for task data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizeTaskInput(array $data): array
    {
        $sanitized = [];

        // Sanitize title
        if (isset($data['title'])) {
            $sanitized['title'] = self::sanitizeString($data['title'], 255);
        }

        // Sanitize description
        if (isset($data['description'])) {
            $sanitized['description'] = self::sanitizeString($data['description'], 2000);
        }

        // Sanitize due_date
        if (isset($data['due_date'])) {
            $sanitized['due_date'] = self::sanitizeDateTime($data['due_date']);
        }

        // Sanitize priority (if provided)
        if (isset($data['priority'])) {
            $priority = strtolower(trim($data['priority']));
            if (in_array($priority, ['low', 'medium', 'high'], true)) {
                $sanitized['priority'] = $priority;
            }
        }

        return $sanitized;
    }

    /**
     * Validate that input doesn't contain malicious content
     */
    public static function validateSafeInput(string $input, string $fieldName): ?string
    {
        if (self::detectSqlInjection($input)) {
            throw new \InvalidArgumentException("Potential SQL injection detected in {$fieldName}");
        }

        if (self::detectXss($input)) {
            throw new \InvalidArgumentException("Potential XSS content detected in {$fieldName}");
        }

        return $input;
    }

    /**
     * Clean array recursively
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizeArray(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            // Sanitize key
            $cleanKey = self::sanitizeString((string)$key, 100);

            if (is_array($value)) {
                $cleaned[$cleanKey] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $cleaned[$cleanKey] = self::sanitizeString($value);
            } elseif (is_int($value)) {
                $cleaned[$cleanKey] = $value;
            } elseif (is_float($value)) {
                $cleaned[$cleanKey] = $value;
            } elseif (is_bool($value)) {
                $cleaned[$cleanKey] = $value;
            }
            // Skip other types (objects, resources, etc.)
        }

        return $cleaned;
    }
}
