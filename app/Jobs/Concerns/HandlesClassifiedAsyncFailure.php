<?php

namespace App\Jobs\Concerns;

use App\Services\Reliability\AsyncDomainFailureWriter;
use App\Services\Reliability\AsyncFailure;
use App\Services\Reliability\AsyncFailureClassifier;
use Throwable;

trait HandlesClassifiedAsyncFailure
{
    public function backoff(): array
    {
        $backoff = config('reliability.job_backoff', [30, 90, 180]);

        if (! is_array($backoff) || $backoff === []) {
            return [30, 90, 180];
        }

        return array_values(array_map(static fn ($value): int => max(1, (int) $value), $backoff));
    }

    protected function classifyFailure(?Throwable $exception): AsyncFailure
    {
        return app(AsyncFailureClassifier::class)->classify($exception);
    }

    protected function failureWriter(): AsyncDomainFailureWriter
    {
        return app(AsyncDomainFailureWriter::class);
    }
}
