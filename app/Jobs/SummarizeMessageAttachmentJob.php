<?php

namespace App\Jobs;

use App\Enums\AttachmentSummaryStatus;
use App\Models\MessageAttachment;
use App\Services\ChatAttachments\AttachmentVisionSummaryService;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizeMessageAttachmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $attachmentId,
    ) {
        $this->onQueue(ChatAttachmentConfig::summaryQueue());
    }

    public function handle(AttachmentVisionSummaryService $summarizer): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment === null || $attachment->isPurged()) {
            return;
        }

        if ($attachment->summary_status === AttachmentSummaryStatus::Ready) {
            return;
        }

        $summarizer->summarize($attachment);
    }

    public function failed(?Throwable $exception): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment !== null && $attachment->summary_status !== AttachmentSummaryStatus::Ready) {
            $attachment->forceFill([
                'summary_status' => AttachmentSummaryStatus::Failed,
                'purge_failure_count' => (int) $attachment->purge_failure_count + 1,
            ])->save();
        }

        try {
            Log::warning('attachment summary job failed', [
                'attachment_id' => $this->attachmentId,
                'error_class' => $exception ? $exception::class : null,
            ]);
        } catch (Throwable) {
        }
    }
}
