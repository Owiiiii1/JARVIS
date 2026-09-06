<?php

namespace App\Services\Reliability;

use App\Enums\AttachmentSummaryStatus;
use App\Enums\KnowledgeAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\StoredFileStatus;
use App\Enums\TelegramGroupAnalysisRunStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AsyncReliabilityReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'failed_jobs' => $this->failedJobs(),
            'memory_runs' => $this->memoryRuns(),
            'group_runs' => $this->groupRuns(),
            'attachments' => $this->attachments(),
            'stored_files' => $this->storedFiles(),
            'knowledge_runs' => $this->knowledgeRuns(),
            'pending_jobs' => $this->pendingJobs(),
        ];
    }

    /**
     * @return list<array{queue: string, job: string, count: int, last_failed_at: ?string}>
     */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $rows = DB::table('failed_jobs')->select('queue', 'payload', 'failed_at')->get();
        $agg = [];

        foreach ($rows as $row) {
            $job = $this->jobClass(is_string($row->payload) ? $row->payload : null);
            $key = $row->queue.'|'.$job;

            if (! isset($agg[$key])) {
                $agg[$key] = [
                    'queue' => (string) $row->queue,
                    'job' => $job,
                    'count' => 0,
                    'last_failed_at' => null,
                ];
            }

            $agg[$key]['count']++;

            if ($agg[$key]['last_failed_at'] === null || $row->failed_at > $agg[$key]['last_failed_at']) {
                $agg[$key]['last_failed_at'] = (string) $row->failed_at;
            }
        }

        return array_values($agg);
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryRuns(): array
    {
        if (! Schema::hasTable('memory_analysis_runs')) {
            return [];
        }

        return [
            'by_status' => $this->counts('memory_analysis_runs', 'status'),
            'oldest_processing_at' => DB::table('memory_analysis_runs')
                ->where('status', MemoryAnalysisRunStatus::Processing->value)
                ->min('updated_at'),
            'failed_categories' => $this->categories('memory_analysis_runs'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupRuns(): array
    {
        if (! Schema::hasTable('telegram_group_analysis_runs')) {
            return [];
        }

        return [
            'by_status' => $this->counts('telegram_group_analysis_runs', 'status'),
            'oldest_processing_at' => DB::table('telegram_group_analysis_runs')
                ->where('status', TelegramGroupAnalysisRunStatus::Processing->value)
                ->min('updated_at'),
            'failed_categories' => $this->categories('telegram_group_analysis_runs'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachments(): array
    {
        if (! Schema::hasTable('message_attachments')) {
            return [];
        }

        return [
            'by_status' => $this->counts('message_attachments', 'summary_status'),
            'oldest_processing_at' => DB::table('message_attachments')
                ->where('summary_status', AttachmentSummaryStatus::Processing->value)
                ->min('updated_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedFiles(): array
    {
        if (! Schema::hasTable('stored_files')) {
            return [];
        }

        return [
            'by_status' => $this->counts('stored_files', 'status'),
            'oldest_processing_at' => DB::table('stored_files')
                ->where('status', StoredFileStatus::Processing->value)
                ->min('updated_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function knowledgeRuns(): array
    {
        if (! Schema::hasTable('knowledge_analysis_runs')) {
            return [];
        }

        return [
            'by_status' => $this->counts('knowledge_analysis_runs', 'status'),
            'oldest_processing_at' => DB::table('knowledge_analysis_runs')
                ->where('status', KnowledgeAnalysisRunStatus::Processing->value)
                ->min('updated_at'),
            'failed_categories' => $this->categories('knowledge_analysis_runs'),
        ];
    }

    /**
     * @return list<array{queue: string, pending: int, reserved: int, oldest_at: mixed}>
     */
    private function pendingJobs(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->select('queue', DB::raw('count(*) as pending'), DB::raw('sum(reserved_at is not null) as reserved'), DB::raw('min(created_at) as oldest_at'))
            ->groupBy('queue')
            ->get()
            ->map(static fn ($row): array => [
                'queue' => (string) $row->queue,
                'pending' => (int) $row->pending,
                'reserved' => (int) $row->reserved,
                'oldest_at' => $row->oldest_at,
            ])
            ->all();
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function counts(string $table, string $column): array
    {
        return DB::table($table)
            ->select($column.' as status', DB::raw('count(*) as count'))
            ->groupBy($column)
            ->get()
            ->map(static fn ($row): array => [
                'status' => (string) $row->status,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @return list<array{category: string, retryable: bool, count: int}>
     */
    private function categories(string $table): array
    {
        $rows = DB::table($table)
            ->where('status', 'failed')
            ->get(['last_error', 'metadata']);
        $agg = [];
        $classifier = app(AsyncFailureClassifier::class);

        foreach ($rows as $row) {
            $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
            $metadata = is_array($metadata) ? $metadata : null;
            $failure = $classifier->classifyStored(is_string($row->last_error) ? $row->last_error : null, $metadata);
            $key = $failure->category->value.'|'.($failure->retryable ? '1' : '0');

            if (! isset($agg[$key])) {
                $agg[$key] = [
                    'category' => $failure->category->value,
                    'retryable' => $failure->retryable,
                    'count' => 0,
                ];
            }

            $agg[$key]['count']++;
        }

        return array_values($agg);
    }

    private function jobClass(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return 'unknown';
        }

        $data = json_decode($payload, true);

        if (is_array($data) && isset($data['displayName']) && is_string($data['displayName'])) {
            return class_basename($data['displayName']);
        }

        return 'unknown';
    }
}
