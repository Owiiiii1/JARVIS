<?php

namespace App\Jobs;

use App\Enums\WatcherStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\Watcher;
use App\Services\Watchers\WatcherEvaluationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EvaluateWatcherJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $watcherId,
    ) {
        $this->onQueue((string) config('watchers.queue', 'default'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function uniqueId(): string
    {
        return 'watcher-'.$this->watcherId;
    }

    public function handle(WatcherEvaluationService $evaluation): void
    {
        $watcher = Watcher::query()->find($this->watcherId);
        if ($watcher === null || $watcher->status !== WatcherStatus::Active) {
            return;
        }

        $evaluation->evaluate($watcher);
    }

    public function failed(?Throwable $exception): void
    {
        $watcher = Watcher::query()->find($this->watcherId);
        if ($watcher === null) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->logFailure('watcher evaluation failed', $failure, [
            'watcher_id' => $this->watcherId,
            'user_id' => $watcher->user_id,
        ]);
    }
}
