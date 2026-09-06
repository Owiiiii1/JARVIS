<?php

namespace App\Services\Reliability;

use App\Enums\AsyncFailureCategory;

final readonly class AsyncFailure
{
    public function __construct(
        public AsyncFailureCategory $category,
        public string $code,
        public bool $retryable,
        public ?string $exceptionClass = null,
    ) {}

    public function lastError(): string
    {
        return $this->category->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'error_category' => $this->category->value,
            'error_code' => $this->code,
            'error_class' => $this->exceptionClass,
            'retryable' => $this->retryable,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
