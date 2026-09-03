<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationContextBuilder;
use Illuminate\Console\Command;

class MemoryBackfillCommand extends Command
{
    protected $signature = 'jarvis:memory:backfill
        {--user= : Internal user id}
        {--conversation= : Internal conversation id}
        {--dry-run : Show work without dispatching jobs}
        {--limit=50 : Max conversations}
        {--chunk=20 : Max turns per conversation}';

    protected $description = 'Queue bounded memory analysis/summary backfill for existing conversations.';

    public function handle(ConversationContextBuilder $context): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $conversationId = $this->option('conversation') !== null ? (int) $this->option('conversation') : null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Conversation::query()->orderBy('id');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($conversationId) {
            $query->whereKey($conversationId);
        }

        $conversations = $query->limit($limit)->get();
        $turns = 0;
        $summaries = 0;

        foreach ($conversations as $conversation) {
            if ($userId && (int) $conversation->user_id !== $userId) {
                continue;
            }

            $user = User::query()->find($conversation->user_id);

            if ($user === null) {
                continue;
            }

            $messages = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderBy('id')
                ->get()
                ->filter(fn (Message $message): bool => $context->isSemanticDialogue($message))
                ->values();

            $pairs = 0;

            for ($index = 0; $index < $messages->count() - 1 && $pairs < $chunk; $index++) {
                $from = $messages[$index];
                $to = $messages[$index + 1];

                if ($from->role->value !== 'user') {
                    continue;
                }

                $turns++;
                $pairs++;

                if ($dryRun) {
                    $this->line("turn user={$user->id} conversation={$conversation->id} from={$from->id} to={$to->id}");
                    continue;
                }

                AnalyzeConversationTurnJob::dispatch(
                    (int) $user->id,
                    (int) $conversation->id,
                    (int) $from->id,
                    (int) $to->id,
                )->onQueue((string) config('memory.queue'));
            }

            if ($messages->count() >= (int) config('memory.summary_message_threshold')) {
                $summaries++;

                if ($dryRun) {
                    $this->line("summary user={$user->id} conversation={$conversation->id} messages={$messages->count()}");
                } else {
                    UpdateConversationSummaryJob::dispatch((int) $user->id, (int) $conversation->id, true)
                        ->onQueue((string) config('memory.queue'));
                }
            }
        }

        $this->info(($dryRun ? 'dry-run ' : '')."conversations={$conversations->count()} turns={$turns} summaries={$summaries}");

        return self::SUCCESS;
    }
}
