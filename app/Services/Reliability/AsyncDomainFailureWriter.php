<?php

namespace App\Services\Reliability;

use App\Enums\AttachmentSummaryStatus;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\StoredFileStatus;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Models\MemoryAnalysisRun;
use App\Models\MessageAttachment;
use App\Models\StoredFile;
use App\Models\TelegramGroupAnalysisRun;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AsyncDomainFailureWriter
{
    public function failMemoryRun(MemoryAnalysisRun $run, AsyncFailure $failure): void
    {
        if ($run->status === MemoryAnalysisRunStatus::Completed) {
            return;
        }

        $run->forceFill([
            'status' => MemoryAnalysisRunStatus::Failed,
            'last_error' => $failure->lastError(),
            'metadata' => $this->mergeMetadata($run->metadata, $failure),
        ])->save();
    }

    public function failGroupRun(TelegramGroupAnalysisRun $run, AsyncFailure $failure): void
    {
        if ($run->status === TelegramGroupAnalysisRunStatus::Completed) {
            return;
        }

        $run->forceFill([
            'status' => TelegramGroupAnalysisRunStatus::Failed,
            'last_error' => $failure->lastError(),
            'metadata' => $this->mergeMetadata($run->metadata, $failure),
        ])->save();
    }

    public function failAttachment(MessageAttachment $attachment, AsyncFailure $failure, bool $skipped = false): void
    {
        if ($attachment->summary_status === AttachmentSummaryStatus::Ready) {
            return;
        }

        $status = $skipped ? AttachmentSummaryStatus::NotRequired : AttachmentSummaryStatus::Failed;

        $attachment->forceFill([
            'summary_status' => $status,
            'metadata' => $this->mergeMetadata($attachment->metadata, $failure),
        ])->save();
    }

    public function failStoredFile(StoredFile $file, AsyncFailure $failure): void
    {
        if ($file->status === StoredFileStatus::Ready || $file->isDeleted()) {
            return;
        }

        $file->forceFill([
            'status' => StoredFileStatus::Failed,
            'metadata' => $this->mergeMetadata($file->metadata, $failure),
        ])->save();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public function mergeMetadata(?array $metadata, AsyncFailure $failure): array
    {
        return array_merge($metadata ?? [], $failure->metadata());
    }

    public function logFailure(string $context, AsyncFailure $failure, array $ids): void
    {
        try {
            Log::warning($context, array_merge($ids, [
                'error_category' => $failure->category->value,
                'error_code' => $failure->code,
                'error_class' => $failure->exceptionClass,
                'retryable' => $failure->retryable,
            ]));
        } catch (Throwable) {
        }
    }
}
