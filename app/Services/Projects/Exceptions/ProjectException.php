<?php

namespace App\Services\Projects\Exceptions;

use RuntimeException;

final class ProjectException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
