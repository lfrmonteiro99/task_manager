<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class RateLimitExceededException extends Exception
{
    public function __construct(int $limit, int $windowSeconds)
    {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds} seconds exceeded");
    }
}
