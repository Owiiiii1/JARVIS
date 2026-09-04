<?php

namespace App\Services\Integrations\Exceptions;

use RuntimeException;

final class IntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
