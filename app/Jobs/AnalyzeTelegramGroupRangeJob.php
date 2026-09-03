<?php

namespace App\Jobs;

use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\Groups\GroupAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeTelegramGroupRangeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue((string) config('group_analysis.queue'));
    }

    public function handle(GroupAnalysisService $analysis): void
    {
        $claimed = DB::transaction(function (): ?TelegramGroupAnalysisRun {
            $run = TelegramGroupAnalysisRun::query()
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                return null;
            }

            if ($run->status === TelegramGroupAnalysisRunStatus::Completed) {
                return null;
            }

            if ($run->status === TelegramGroupAnalysisRunStatus::Processing) {
                return null;
            }

            if (! in_array($run->status, [TelegramGroupAnalysisRunStatus::Queued, TelegramGroupAnalysisRunStatus::Failed], true)) {
                return null;
            }

            $run->forceFill([
                'status' => TelegramGroupAnalysisRunStatus::Processing,
                'attempts' => (int) $run->attempts + 1,
                'started_at' => $run->started_at ?? now(),
                'last_error' => null,
            ])->save();

            return $run;
        });

        if ($claimed === null) {
            return;
        }

        try {
            $result = $analysis->process($claimed);

            $claimed->forceFill([
                'status' => TelegramGroupAnalysisRunStatus::Completed,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'completed_at' => now(),
                'last_error' => null,
                'metadata' => $result['metadata'],
            ])->save();
        } catch (Throwable $exception) {
            $claimed->forceFill([
                'status' => TelegramGroupAnalysisRunStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            Log::warning('telegram group analysis failed', [
                'job' => self::class,
                'run_id' => $claimed->id,
                'telegram_group_id' => $claimed->telegram_group_id,
                'error_class' => $exception::class,
            ]);
        }
    }
}
