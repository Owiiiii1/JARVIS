<?php

namespace App\Services\Storage\Exceptions;

use InvalidArgumentException;

class StoredFileException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
