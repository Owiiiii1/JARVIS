<?php

namespace App\Services\Groups\Exceptions;

use RuntimeException;

class TelegramGroupException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly ?int $telegramErrorCode = null,
        public readonly ?string $telegramErrorClass = null,
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
