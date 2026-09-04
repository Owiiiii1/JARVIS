<?php

namespace App\Services\ChatAttachments\Exceptions;

use InvalidArgumentException;

class ChatAttachmentException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
