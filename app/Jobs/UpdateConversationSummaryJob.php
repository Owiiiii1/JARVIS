<?php

namespace App\Jobs;

use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use App\Models\Conversation;
use App\Models\MemoryAnalysisRun;
use App\Models\User;
use App\Services\Memory\ConversationSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateConversationSummaryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly bool $force = false,
    ) {
        $this->onQueue((string) config('memory.queue'));
    }

    public function handle(ConversationSummaryService $summaries): void
    {
        $user = User::query()->find($this->userId);
        $conversation = Conversation::query()->find($this->conversationId);

        if ($user === null || $conversation === null || (int) $conversation->user_id !== $this->userId) {
            return;
        }

        if (! $this->force && ! $summaries->shouldUpdate($conversation)) {
            return;
        }

        $previous = $summaries->current($conversation);
        $fromId = $previous?->to_message_id;
        $toId = $conversation->messages()->max('id');

        $run = MemoryAnalysisRun::query()->firstOrNew([
            'conversation_id' => $this->conversationId,
            'type' => MemoryAnalysisRunType::Summary,
            'from_message_id' => $fromId,
            'to_message_id' => $toId,
        ]);

        if ($run->exists && $run->status === MemoryAnalysisRunStatus::Completed) {
            return;
        }

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
            $result = $summaries->update($user, $conversation);

            if ($result === null) {
                $run->forceFill([
                    'status' => MemoryAnalysisRunStatus::Completed,
                    'completed_at' => now(),
                    'metadata' => ['skipped' => true],
                ])->save();

                return;
            }

            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Completed,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'completed_at' => now(),
                'metadata' => [
                    'summary_id' => $result['summary']->id,
                    'version' => $result['summary']->version,
                ],
            ])->save();

            Log::info('conversation summary updated', [
                'job' => self::class,
                'run_id' => $run->id,
                'user_id' => $this->userId,
                'conversation_id' => $this->conversationId,
                'summary_id' => $result['summary']->id,
                'version' => $result['summary']->version,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            Log::warning('conversation summary failed', [
                'job' => self::class,
                'run_id' => $run->id,
                'user_id' => $this->userId,
                'conversation_id' => $this->conversationId,
                'error_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
