<?php

namespace App\Console\Commands;

use App\Models\WatcherOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneWatcherOccurrencesCommand extends Command
{
    protected $signature = 'jarvis:watchers:prune {--days= : Retention days} {--dry-run=1 : Show work without deleting (default)}';

    protected $description = 'Prune old watcher occurrences. Dry-run by default. Do not run live destructive prune in this milestone.';

    public function handle(): int
    {
        $days = $this->option('days') !== null && $this->option('days') !== ''
            ? max(1, (int) $this->option('days'))
            : max(1, (int) config('watchers.retention_days', 90));
        $dryRun = $this->option('dry-run') === null
            || $this->option('dry-run') === true
            || $this->option('dry-run') === '1'
            || $this->option('dry-run') === 'true';

        if ($this->option('dry-run') === '0' || $this->option('dry-run') === 'false') {
            $dryRun = false;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $query = WatcherOccurrence::query()->where('detected_at', '<', $cutoff);
        $count = $query->count();
        $this->line('occurrences older than '.$cutoff->toIso8601String().': '.$count);

        if (! $dryRun) {
            $query->delete();
            $this->info('Deleted '.$count.' occurrence(s).');
        } else {
            $this->info('Dry-run only. No occurrences deleted.');
        }

        return self::SUCCESS;
    }
}
