<?php

namespace App\Console\Commands;

use App\Services\Watchers\WatcherDispatchService;
use Illuminate\Console\Command;

class DispatchWatchersCommand extends Command
{
    protected $signature = 'jarvis:watchers:dispatch {--limit=40}';

    protected $description = 'Dispatch due watcher evaluations without overlapping duplicate checks';

    public function handle(WatcherDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));
        $this->info('Dispatched '.$count.' watcher evaluation(s).');

        return self::SUCCESS;
    }
}
