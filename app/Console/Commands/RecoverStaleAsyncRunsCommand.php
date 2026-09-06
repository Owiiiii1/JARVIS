<?php

namespace App\Console\Commands;

use App\Services\Reliability\StaleAsyncRunRecovery;
use Illuminate\Console\Command;

class RecoverStaleAsyncRunsCommand extends Command
{
    protected $signature = 'jarvis:reliability:recover-stale
        {--dry-run : Report matching rows without updating them}
        {--minutes= : Processing age threshold in minutes}';

    protected $description = 'Mark stuck processing analysis rows as failed after a safe age threshold.';

    public function handle(StaleAsyncRunRecovery $recovery): int
    {
        $minutes = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) config('reliability.stale_running_minutes', 30);
        $execute = ! (bool) $this->option('dry-run');
        $counts = $recovery->recover($minutes, $execute);

        $this->info(($execute ? 'recovered' : 'dry-run').' memory='.$counts['memory'].' groups='.$counts['groups'].' attachments='.$counts['attachments'].' stored_files='.$counts['stored_files'].' minutes='.$minutes);

        return self::SUCCESS;
    }
}
