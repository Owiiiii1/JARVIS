<?php

namespace App\Services\Memory;

use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

final class MemoryTurnDispatcher
{
    public function __construct(
        private readonly ConversationSummaryService $summaries,
    ) {}

    public function afterSuccessfulTurn(User $user, Conversation $conversation, Message $inbound, ?Message $assistant): void
    {
        $toId = $assistant?->id ?? $inbound->id;

        AnalyzeConversationTurnJob::dispatch(
            (int) $user->id,
            (int) $conversation->id,
            (int) $inbound->id,
            (int) $toId,
        )->onQueue((string) config('memory.queue'));

        if ($this->summaries->shouldUpdate($conversation)) {
            UpdateConversationSummaryJob::dispatch(
                (int) $user->id,
                (int) $conversation->id,
            )->onQueue((string) config('memory.queue'));
        }
    }
}
