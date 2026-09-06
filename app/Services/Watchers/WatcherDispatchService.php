<?php

namespace App\Services\Watchers;

use App\Enums\WatcherStatus;
use App\Jobs\EvaluateWatcherJob;
use App\Models\Watcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WatcherDispatchService
{
    public function dispatchDue(int $limit = 40): int
    {
        $now = CarbonImmutable::now('UTC');
        $ids = Watcher::query()
            ->where('status', WatcherStatus::Active)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
            })
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $claimed = DB::transaction(function () use ($id, $now): bool {
                $watcher = Watcher::query()->whereKey($id)->lockForUpdate()->first();
                if ($watcher === null || $watcher->status !== WatcherStatus::Active) {
                    return false;
                }
                if ($watcher->next_check_at !== null && $watcher->next_check_at->greaterThan($now)) {
                    return false;
                }

                $watcher->forceFill([
                    'next_check_at' => $now->addSeconds(WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type)),
                ])->save();

                return true;
            });

            if (! $claimed) {
                continue;
            }

            EvaluateWatcherJob::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }
}
