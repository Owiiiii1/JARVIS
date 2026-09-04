<?php

namespace App\Services\Groups\Exceptions;

use RuntimeException;

final class GroupSearchException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
