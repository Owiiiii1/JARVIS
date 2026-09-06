<?php

namespace App\Services\Knowledge\Exceptions;

use RuntimeException;

final class KnowledgeException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
