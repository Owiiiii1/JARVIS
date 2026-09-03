<?php

namespace App\Services\Memory;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

final class SummaryPromptBuilder
{
    /**
     * @param  Collection<int, Message>  $messages
     */
    public function incremental(?string $previousSummary, Conversation $conversation, Collection $messages): string
    {
        $lines = [
            'Task: write a compact conversation summary for later retrieval. JSON only.',
            'Schema: {"summary":"string"}',
            'Keep useful meaning: what was discussed, decisions, open questions, important facts, current work state.',
            'Do not write a transcript. Do not include secrets, API keys, tokens, or credentials.',
            'Language: same as the conversation.',
            'Conversation title: '.$conversation->title,
        ];

        if (filled($previousSummary)) {
            $lines[] = 'Previous summary:';
            $lines[] = trim($previousSummary);
            $lines[] = 'New messages since previous summary:';
        } else {
            $lines[] = 'Messages:';
        }

        foreach ($messages as $message) {
            $lines[] = sprintf(
                '[%s] %s',
                $message->role->value,
                trim((string) $message->body),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $chunkSummaries
     */
    public function reduce(Conversation $conversation, array $chunkSummaries): string
    {
        $lines = [
            'Task: merge chunk summaries into one compact conversation summary. JSON only.',
            'Schema: {"summary":"string"}',
            'Keep decisions, open questions, important facts, current work state. No transcript. No secrets.',
            'Conversation title: '.$conversation->title,
            'Chunk summaries:',
        ];

        foreach ($chunkSummaries as $index => $summary) {
            $lines[] = 'Chunk '.($index + 1).':';
            $lines[] = $summary;
        }

        return implode("\n", $lines);
    }
}
