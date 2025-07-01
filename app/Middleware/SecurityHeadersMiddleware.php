<?php

declare(strict_types=1);

namespace App\Middleware;

class SecurityHeadersMiddleware
{
    public function addSecurityHeaders(): void
    {
        // Prevent XSS attacks
        header('X-XSS-Protection: 1; mode=block');

        // Prevent content type sniffing
        header('X-Content-Type-Options: nosniff');

        // Prevent clickjacking
        header('X-Frame-Options: DENY');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (CSP)
        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            "child-src 'none'",
            "worker-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'"
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));

        // Strict Transport Security (HTTPS only - commented for development)
        // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // Feature Policy / Permissions Policy
        $permissions = [
            'geolocation=()',
            'microphone=()',
            'camera=()',
            'magnetometer=()',
            'gyroscope=()',
            'speaker=()',
            'vibrate=()',
            'fullscreen=()',
            'payment=()'
        ];
        header('Permissions-Policy: ' . implode(', ', $permissions));

        // API specific headers
        header('X-API-Version: 1.0');
        header('X-Powered-By: Task Manager API');

        // Cache control for API responses
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public function handleCors(): void
    {
        // CORS headers for API access
        $allowedOrigins = $this->getAllowedOrigins();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true) || $this->isDevelopmentMode()) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // 24 hours

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * @return array<string>
     */
    private function getAllowedOrigins(): array
    {
        $origins = getenv('ALLOWED_ORIGINS');
        if ($origins === false || empty($origins)) {
            return [];
        }

        return array_map('trim', explode(',', $origins));
    }

    private function isDevelopmentMode(): bool
    {
        return getenv('APP_ENV') === 'development' || getenv('APP_ENV') === 'test';
    }
}
