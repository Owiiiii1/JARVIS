<?php

namespace App\Jobs;

use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\Groups\GroupAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyzeTelegramGroupRangeJob implements ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 170;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue((string) config('group_analysis.queue'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
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
            $failure = $this->classifyFailure($exception);

            if (! $failure->retryable) {
                $this->failureWriter()->failGroupRun($claimed, $failure);
                $this->failureWriter()->logFailure('telegram group analysis failed', $failure, [
                    'job' => self::class,
                    'run_id' => $claimed->id,
                    'telegram_group_id' => $claimed->telegram_group_id,
                ]);

                return;
            }

            $this->failureWriter()->logFailure('telegram group analysis retryable failure', $failure, [
                'job' => self::class,
                'run_id' => $claimed->id,
                'telegram_group_id' => $claimed->telegram_group_id,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = TelegramGroupAnalysisRun::query()->find($this->runId);

        if ($run === null || $run->status === TelegramGroupAnalysisRunStatus::Completed) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->failGroupRun($run, $failure);
        $this->failureWriter()->logFailure('telegram group analysis job failed', $failure, [
            'job' => self::class,
            'run_id' => $run->id,
            'telegram_group_id' => $run->telegram_group_id,
        ]);
    }
}
