<?php

namespace App\Services\Integrations\Exceptions;

use RuntimeException;

final class IntegrationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
