<?php

declare(strict_types=1);

namespace App\Audit;

use RuntimeException;

class AiRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly string $category, public readonly bool $retryable = false, public readonly int $retryAfter = 30)
    {
        parent::__construct($message);
    }
}
