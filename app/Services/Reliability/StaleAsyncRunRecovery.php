<?php

namespace App\Services\Reliability;

use App\Enums\AsyncFailureCategory;
use App\Enums\AttachmentSummaryStatus;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\StoredFileStatus;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Models\MemoryAnalysisRun;
use App\Models\MessageAttachment;
use App\Models\StoredFile;
use App\Models\TelegramGroupAnalysisRun;
use Carbon\CarbonImmutable;

final class StaleAsyncRunRecovery
{
    public function __construct(
        private readonly AsyncDomainFailureWriter $writer,
    ) {}

    /**
     * @return array{memory: int, groups: int, attachments: int, stored_files: int}
     */
    public function recover(int $minutes, bool $execute): array
    {
        $threshold = CarbonImmutable::now()->subMinutes(max(5, $minutes));
        $failure = new AsyncFailure(
            AsyncFailureCategory::Unknown,
            'stale_running',
            true,
        );

        $memory = MemoryAnalysisRun::query()
            ->where('status', MemoryAnalysisRunStatus::Processing)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        $groups = TelegramGroupAnalysisRun::query()
            ->where('status', TelegramGroupAnalysisRunStatus::Processing)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        $attachments = MessageAttachment::query()
            ->where('summary_status', AttachmentSummaryStatus::Processing)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        $files = StoredFile::query()
            ->where('status', StoredFileStatus::Processing)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        if ($execute) {
            foreach ($memory as $run) {
                $this->writer->failMemoryRun($run, $failure);
            }

            foreach ($groups as $run) {
                $this->writer->failGroupRun($run, $failure);
            }

            foreach ($attachments as $attachment) {
                $this->writer->failAttachment($attachment, $failure);
            }

            foreach ($files as $file) {
                $this->writer->failStoredFile($file, $failure);
            }
        }

        return [
            'memory' => $memory->count(),
            'groups' => $groups->count(),
            'attachments' => $attachments->count(),
            'stored_files' => $files->count(),
        ];
    }
}
