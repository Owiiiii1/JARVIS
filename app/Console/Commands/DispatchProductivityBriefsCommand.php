<?php

namespace App\Console\Commands;

use App\Services\Productivity\ProductivityBriefDispatchService;
use Illuminate\Console\Command;

class DispatchProductivityBriefsCommand extends Command
{
    protected $signature = 'jarvis:briefs:dispatch {--limit=40}';

    protected $description = 'Deliver opted-in daily, evening, and weekly productivity briefs';

    public function handle(ProductivityBriefDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));

        $this->info('Delivered '.$count.' productivity brief(s).');

        return self::SUCCESS;
    }
}
