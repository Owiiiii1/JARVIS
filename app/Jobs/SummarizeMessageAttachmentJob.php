<?php

namespace App\Jobs;

use App\Enums\AsyncFailureCategory;
use App\Enums\AttachmentSummaryStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\MessageAttachment;
use App\Services\ChatAttachments\AttachmentVisionSummaryService;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SummarizeMessageAttachmentJob implements ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $attachmentId,
    ) {
        $this->onQueue(ChatAttachmentConfig::summaryQueue());
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function handle(AttachmentVisionSummaryService $summarizer): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment === null) {
            return;
        }

        if ($attachment->summary_status === AttachmentSummaryStatus::Ready) {
            return;
        }

        if ($attachment->isPurged() || ! $attachment->isImage()) {
            $this->failureWriter()->failAttachment(
                $attachment,
                $this->classifyFailure(new ClassifiedAsyncException(
                    AsyncFailureCategory::StaleSource,
                    $attachment->isPurged() ? 'stale_source' : 'not_required',
                )),
                skipped: true,
            );

            return;
        }

        try {
            $summarizer->summarize($attachment);
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);
            $skipped = $failure->category === AsyncFailureCategory::StaleSource;

            if (! $failure->retryable) {
                $this->failureWriter()->failAttachment($attachment->fresh() ?? $attachment, $failure, $skipped);
                $this->failureWriter()->logFailure('attachment summary failed', $failure, [
                    'attachment_id' => $this->attachmentId,
                ]);

                return;
            }

            $this->failureWriter()->logFailure('attachment summary retryable failure', $failure, [
                'attachment_id' => $this->attachmentId,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment === null || $attachment->summary_status === AttachmentSummaryStatus::Ready) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $skipped = $failure->category === AsyncFailureCategory::StaleSource;
        $this->failureWriter()->failAttachment($attachment, $failure, $skipped);
        $this->failureWriter()->logFailure('attachment summary job failed', $failure, [
            'attachment_id' => $this->attachmentId,
        ]);
    }
}
