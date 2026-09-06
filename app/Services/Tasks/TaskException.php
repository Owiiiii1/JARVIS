<?php

namespace App\Services\Tasks;

use RuntimeException;

class TaskException extends RuntimeException
{
    /**
     * @param  list<mixed>  $candidates
     */
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly array $candidates = [],
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
