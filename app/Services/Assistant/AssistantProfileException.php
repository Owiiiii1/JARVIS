<?php

namespace App\Services\Assistant;

use RuntimeException;

class AssistantProfileException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly array $missing = [],
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
