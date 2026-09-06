<?php

namespace App\Services\Watchers\Exceptions;

use RuntimeException;

final class WatcherException extends RuntimeException
{
    /**
     * @param  list<mixed>  $candidates
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly array $candidates = [],
    ) {
        parent::__construct($message);
    }
}
