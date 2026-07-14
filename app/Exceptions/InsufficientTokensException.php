<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientTokensException extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
    ) {
        parent::__construct("Insufficient tokens. Required: {$required}; available: {$available}.");
    }
}
