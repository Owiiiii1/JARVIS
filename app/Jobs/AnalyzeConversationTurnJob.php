<?php

namespace App\Jobs;

use App\Enums\AsyncFailureCategory;
use App\Enums\ConversationKind;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\Conversation;
use App\Models\MemoryAnalysisRun;
use App\Models\Message;
use App\Models\User;
use App\Services\Memory\ConversationTurnAnalyzer;
use App\Services\Memory\UserProfileService;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeConversationTurnJob implements ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly int $fromMessageId,
        public readonly int $toMessageId,
    ) {
        $this->onQueue((string) config('memory.queue'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function handle(ConversationTurnAnalyzer $analyzer, UserProfileService $profiles): void
    {
        $existing = $this->existingRun();

        if ($existing !== null && $existing->status === MemoryAnalysisRunStatus::Completed) {
            return;
        }

        $user = User::query()->find($this->userId);
        $conversation = Conversation::query()->find($this->conversationId);
        $from = Message::query()->find($this->fromMessageId);
        $to = Message::query()->find($this->toMessageId);

        if (
            $user === null
            || $conversation === null
            || $from === null
            || $to === null
            || (int) $conversation->user_id !== $this->userId
            || (int) $from->user_id !== $this->userId
            || (int) $to->user_id !== $this->userId
            || $conversation->kind !== ConversationKind::Personal
        ) {
            $this->markExistingRunUnavailable($this->unavailableCategory($user, $conversation, $from, $to));

            return;
        }

        $run = MemoryAnalysisRun::query()->firstOrNew([
            'conversation_id' => $this->conversationId,
            'type' => MemoryAnalysisRunType::Turn,
            'from_message_id' => $this->fromMessageId,
            'to_message_id' => $this->toMessageId,
        ]);

        $startedAt = microtime(true);
        $run->fill([
            'user_id' => $this->userId,
            'status' => MemoryAnalysisRunStatus::Processing,
            'attempts' => (int) $run->attempts + 1,
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ]);
        $run->save();

        try {
            $result = $analyzer->analyze($user, $conversation, $from, $to);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $stats = $result['stats'];

            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Completed,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'completed_at' => now(),
                'last_error' => null,
                'metadata' => $stats->toArray(),
            ])->save();

            $profiles->maybeUpdate($user, $stats->created + $stats->reinforced + $stats->superseded);

            Log::info('memory analysis completed', [
                'job' => self::class,
                'run_id' => $run->id,
                'user_id' => $this->userId,
                'conversation_id' => $this->conversationId,
                'from_message_id' => $this->fromMessageId,
                'to_message_id' => $this->toMessageId,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'duration_ms' => $durationMs,
                'created' => $stats->created,
                'reinforced' => $stats->reinforced,
                'superseded' => $stats->superseded,
            ]);
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);

            if (! $failure->retryable) {
                $this->failureWriter()->failMemoryRun($run, $failure);
                $this->failureWriter()->logFailure('memory analysis failed', $failure, $this->logIds($run));

                return;
            }

            $this->failureWriter()->logFailure('memory analysis retryable failure', $failure, $this->logIds($run));

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->existingRun();

        if ($run === null || $run->status === MemoryAnalysisRunStatus::Completed) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->failMemoryRun($run, $failure);
        $this->failureWriter()->logFailure('memory analysis job failed', $failure, $this->logIds($run));
    }

    private function existingRun(): ?MemoryAnalysisRun
    {
        return MemoryAnalysisRun::query()
            ->where('conversation_id', $this->conversationId)
            ->where('type', MemoryAnalysisRunType::Turn)
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('from_message_id', $this->fromMessageId)
                        ->where('to_message_id', $this->toMessageId);
                })->orWhere(function ($inner): void {
                    $inner->where('user_id', $this->userId)
                        ->whereNull('from_message_id')
                        ->whereNull('to_message_id');
                });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function markExistingRunUnavailable(AsyncFailureCategory $category): void
    {
        $run = $this->existingRun();

        if ($run === null) {
            return;
        }

        $failure = $this->classifyFailure(new ClassifiedAsyncException($category, $category->value));
        $this->failureWriter()->failMemoryRun($run, $failure);
        $this->failureWriter()->logFailure('memory analysis failed', $failure, $this->logIds($run));
    }

    private function unavailableCategory(?User $user, ?Conversation $conversation, ?Message $from, ?Message $to): AsyncFailureCategory
    {
        if ($user === null || $conversation === null || $from === null || $to === null) {
            return AsyncFailureCategory::MissingSource;
        }

        if (
            (int) $conversation->user_id !== $this->userId
            || (int) $from->user_id !== $this->userId
            || (int) $to->user_id !== $this->userId
        ) {
            return AsyncFailureCategory::Ownership;
        }

        return AsyncFailureCategory::StaleSource;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function logIds(MemoryAnalysisRun $run): array
    {
        return [
            'job' => self::class,
            'run_id' => $run->id,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'from_message_id' => $this->fromMessageId,
            'to_message_id' => $this->toMessageId,
        ];
    }
}
