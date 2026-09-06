<?php

namespace App\Console\Commands;

use App\Services\Productivity\ProactiveDispatchService;
use Illuminate\Console\Command;

class DispatchProactiveSuggestionsCommand extends Command
{
    protected $signature = 'jarvis:proactive:dispatch {--limit=80}';

    protected $description = 'Emit bounded proactive suggestions from deterministic task events';

    public function handle(ProactiveDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));

        $this->info('Recorded '.$count.' proactive suggestion(s).');

        return self::SUCCESS;
    }
}
