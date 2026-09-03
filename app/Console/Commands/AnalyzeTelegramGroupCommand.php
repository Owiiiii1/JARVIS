<?php

namespace App\Console\Commands;

use App\Enums\TelegramGroupAnalysisRunType;
use App\Models\TelegramGroup;
use App\Services\Groups\GroupAnalysisRunService;
use App\Services\Groups\GroupTimeRangeService;
use App\Services\Groups\TelegramGroupDiscoveryService;
use Illuminate\Console\Command;

class AnalyzeTelegramGroupCommand extends Command
{
    protected $signature = 'jarvis:groups:analyze
        {--group= : Internal telegram_groups.id}
        {--from= : Local start date Y-m-d}
        {--to= : Local end date Y-m-d}
        {--dry-run : Print the UTC range without queueing}';

    protected $description = 'Queue a manual Telegram group analysis run for a local date range';

    public function handle(
        GroupAnalysisRunService $runs,
        GroupTimeRangeService $ranges,
        TelegramGroupDiscoveryService $discovery,
    ): int {
        $groupId = (int) $this->option('group');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($groupId <= 0 || $from === '' || $to === '') {
            $this->error('Provide --group, --from and --to.');

            return self::FAILURE;
        }

        $group = TelegramGroup::query()->find($groupId);

        if ($group === null) {
            $this->error('Group not found.');

            return self::FAILURE;
        }

        $span = $ranges->customLocalDates($group, $from, $to);

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run. UTC from '.$span['from']->toIso8601String().' to '.$span['to']->toIso8601String().'.');

            return self::SUCCESS;
        }

        $owner = $discovery->owner();
        $run = $runs->queue($owner, $group, $span['from'], $span['to'], TelegramGroupAnalysisRunType::RangeBundle);

        $this->info('Queued analysis run '.$run->id.' with status '.$run->status->value.'.');

        return self::SUCCESS;
    }
}
