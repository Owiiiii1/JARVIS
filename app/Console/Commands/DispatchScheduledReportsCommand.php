<?php

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReportDispatchService;
use Illuminate\Console\Command;

class DispatchScheduledReportsCommand extends Command
{
    protected $signature = 'jarvis:reports:dispatch {--limit=40}';

    protected $description = 'Dispatch due scheduled composite reports once per local slot';

    public function handle(ScheduledReportDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));
        $this->info('Delivered '.$count.' scheduled report(s).');

        return self::SUCCESS;
    }
}
