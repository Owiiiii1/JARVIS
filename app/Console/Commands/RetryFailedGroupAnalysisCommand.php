<?php

namespace App\Console\Commands;

use App\Services\Reliability\FailedAsyncRetryService;
use Illuminate\Console\Command;

class RetryFailedGroupAnalysisCommand extends Command
{
    protected $signature = 'jarvis:groups:retry-failed
        {--execute : Dispatch eligible jobs; default is dry-run}
        {--limit= : Max eligible runs to consider}
        {--hours= : Only runs updated within this many hours}
        {--category= : Optional AsyncFailureCategory value}';

    protected $description = 'Retry eligible transient Telegram group analysis failures. Dry-run unless --execute.';

    public function handle(FailedAsyncRetryService $retry): int
    {
        $result = $retry->retryGroups(
            limit: max(1, (int) ($this->option('limit') ?: config('reliability.retry_limit', 20))),
            hours: max(1, (int) ($this->option('hours') ?: config('reliability.retry_hours', 168))),
            category: $this->option('category') !== null ? (string) $this->option('category') : null,
            execute: (bool) $this->option('execute'),
        );

        $this->info((($this->option('execute') ? 'execute' : 'dry-run')).' eligible='.$result['eligible'].' skipped='.$result['skipped'].' dispatched='.$result['dispatched']);

        return self::SUCCESS;
    }
}
