<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TrendsRemakeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
