<?php

namespace App\Console\Commands;

use App\Services\Reliability\AsyncReliabilityReport;
use Illuminate\Console\Command;

class ReportAsyncReliabilityCommand extends Command
{
    protected $signature = 'jarvis:reliability:report';

    protected $description = 'Show compact async reliability diagnostics without payloads or transcripts.';

    public function handle(AsyncReliabilityReport $report): int
    {
        $data = $report->build();

        $this->info('Failed Laravel jobs (class names only)');
        $this->table(
            ['queue', 'job', 'count', 'last_failed_at'],
            array_map(static fn (array $row): array => [
                $row['queue'],
                $row['job'],
                $row['count'],
                $row['last_failed_at'] ?? '',
            ], $data['failed_jobs']),
        );

        $this->info('Memory analysis runs');
        $this->line('oldest_processing_at: '.($data['memory_runs']['oldest_processing_at'] ?? 'none'));
        $this->table(['status', 'count'], $data['memory_runs']['by_status'] ?? []);
        $this->table(
            ['category', 'retryable', 'count'],
            array_map(static fn (array $row): array => [
                $row['category'],
                $row['retryable'] ? 'yes' : 'no',
                $row['count'],
            ], $data['memory_runs']['failed_categories'] ?? []),
        );

        $this->info('Telegram group analysis runs');
        $this->line('oldest_processing_at: '.($data['group_runs']['oldest_processing_at'] ?? 'none'));
        $this->table(['status', 'count'], $data['group_runs']['by_status'] ?? []);
        $this->table(
            ['category', 'retryable', 'count'],
            array_map(static fn (array $row): array => [
                $row['category'],
                $row['retryable'] ? 'yes' : 'no',
                $row['count'],
            ], $data['group_runs']['failed_categories'] ?? []),
        );

        $this->info('Attachment summaries');
        $this->line('oldest_processing_at: '.($data['attachments']['oldest_processing_at'] ?? 'none'));
        $this->table(['status', 'count'], $data['attachments']['by_status'] ?? []);

        $this->info('Pending queue rows');
        $this->table(
            ['queue', 'pending', 'reserved', 'oldest_at'],
            array_map(static fn (array $row): array => [
                $row['queue'],
                $row['pending'],
                $row['reserved'],
                $row['oldest_at'] ?? '',
            ], $data['pending_jobs']),
        );

        return self::SUCCESS;
    }
}
