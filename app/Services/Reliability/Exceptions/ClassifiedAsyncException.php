<?php

namespace App\Services\Reliability\Exceptions;

use App\Enums\AsyncFailureCategory;
use RuntimeException;
use Throwable;

final class ClassifiedAsyncException extends RuntimeException
{
    public function __construct(
        public readonly AsyncFailureCategory $category,
        public readonly string $failureCode,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failureCode !== '' ? $failureCode : $category->value, 0, $previous);
    }
}
