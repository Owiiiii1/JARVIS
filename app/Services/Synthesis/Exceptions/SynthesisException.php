<?php

namespace App\Services\Synthesis\Exceptions;

use RuntimeException;

final class SynthesisException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
