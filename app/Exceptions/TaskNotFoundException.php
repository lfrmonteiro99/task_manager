<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class TaskNotFoundException extends Exception
{
    public function __construct(int $taskId)
    {
        parent::__construct("Task with ID {$taskId} was not found");
    }
}
