<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupAnalysisRunType;
use App\Jobs\AnalyzeTelegramGroupRangeJob;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\User;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;

final class GroupAnalysisRunService
{
    public function __construct(
        private readonly GroupTimeRangeService $ranges,
    ) {}

    public function assertCanAnalyze(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::GROUP_ANALYSIS)) {
            throw new GroupAnalysisException('Not allowed to run group analysis.');
        }
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function rangeForPreset(TelegramGroup $group, string $preset, ?string $fromDate, ?string $toDate): array
    {
        return match ($preset) {
            'today' => $this->ranges->today($group),
            'yesterday' => $this->ranges->yesterday($group),
            'last_7_days' => $this->ranges->lastDays($group, 7),
            'custom' => $this->ranges->customLocalDates($group, (string) $fromDate, (string) $toDate),
            default => throw new GroupAnalysisException('Unknown analysis preset.'),
        };
    }

    public function queue(
        User $actor,
        TelegramGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        TelegramGroupAnalysisRunType $type = TelegramGroupAnalysisRunType::RangeBundle,
    ): TelegramGroupAnalysisRun {
        $this->assertCanAnalyze($actor);

        $key = TelegramGroupAnalysisRun::idempotencyKey(
            (int) $group->id,
            $type->value,
            $from,
            $to,
        );

        $existing = TelegramGroupAnalysisRun::query()
            ->where('telegram_group_id', $group->id)
            ->where('idempotency_key', $key)
            ->whereIn('status', [
                TelegramGroupAnalysisRunStatus::Queued,
                TelegramGroupAnalysisRunStatus::Processing,
            ])
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $run = TelegramGroupAnalysisRun::query()->create([
            'telegram_group_id' => $group->id,
            'analysis_type' => $type,
            'from_at' => $from,
            'to_at' => $to,
            'status' => TelegramGroupAnalysisRunStatus::Queued,
            'attempts' => 0,
            'idempotency_key' => $key,
            'metadata' => [
                'preset_range' => true,
            ],
        ]);

        $job = new AnalyzeTelegramGroupRangeJob((int) $run->id);

        $this->dispatchJob($job);

        return $run;
    }

    public function retry(User $actor, TelegramGroupAnalysisRun $run): TelegramGroupAnalysisRun
    {
        $this->assertCanAnalyze($actor);

        if ($run->status === TelegramGroupAnalysisRunStatus::Processing) {
            return $run;
        }

        if ($run->status === TelegramGroupAnalysisRunStatus::Queued) {
            $this->dispatchJob(new AnalyzeTelegramGroupRangeJob((int) $run->id));

            return $run;
        }

        if ($run->status !== TelegramGroupAnalysisRunStatus::Failed) {
            throw new GroupAnalysisException('Only failed analysis runs can be retried.');
        }

        $run->forceFill([
            'status' => TelegramGroupAnalysisRunStatus::Queued,
            'last_error' => null,
        ])->save();

        $this->dispatchJob(new AnalyzeTelegramGroupRangeJob((int) $run->id));

        return $run->fresh();
    }

    private function dispatchJob(AnalyzeTelegramGroupRangeJob $job): void
    {
        if (app()->environment('testing') || config('queue.default') === 'sync') {
            dispatch_sync($job);

            return;
        }

        dispatch($job)->onQueue((string) config('group_analysis.queue'));
    }
}
