<?php

namespace App\Jobs;

use App\Enums\ConversationKind;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use App\Models\Conversation;
use App\Models\MemoryAnalysisRun;
use App\Models\Message;
use App\Models\User;
use App\Services\Memory\ConversationTurnAnalyzer;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use App\Services\Memory\UserProfileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeConversationTurnJob implements ShouldQueue
{
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
    }

    public function handle(ConversationTurnAnalyzer $analyzer, UserProfileService $profiles): void
    {
        $run = MemoryAnalysisRun::query()->firstOrNew([
            'conversation_id' => $this->conversationId,
            'type' => MemoryAnalysisRunType::Turn,
            'from_message_id' => $this->fromMessageId,
            'to_message_id' => $this->toMessageId,
        ]);

        if ($run->exists && $run->status === MemoryAnalysisRunStatus::Completed) {
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
            $result = $analyzer->analyze($user, $conversation, $from, $to);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $stats = $result['stats'];

            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Completed,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'completed_at' => now(),
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
            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            Log::warning('memory analysis failed', [
                'job' => self::class,
                'run_id' => $run->id,
                'user_id' => $this->userId,
                'conversation_id' => $this->conversationId,
                'from_message_id' => $this->fromMessageId,
                'to_message_id' => $this->toMessageId,
                'error_class' => $exception::class,
            ]);

            if ($exception instanceof MemoryAnalysisException) {
                return;
            }

            throw $exception;
        }
    }
}
