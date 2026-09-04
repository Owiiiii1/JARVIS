<?php

namespace App\Services\Memory;

use App\Models\Message;
use Illuminate\Support\Collection;

final class MemoryAnalysisPromptBuilder
{
    /**
     * @param  Collection<int, Message>  $messages
     * @param  list<array{kind: string, key: string, content: string, status: string}>  $existingMemories
     */
    public function build(Collection $messages, array $existingMemories): string
    {
        $lines = [
            'Task: extract durable personal memory from this conversation turn.',
            'Return JSON only. No markdown. No commentary.',
            'Schema:',
            '{"topics":[{"name":"string","description":"string|null","confidence":0.0,"message_ids":[1]}],"memories":[{"kind":"fact|preference|instruction|relationship|project_context|other","content":"string","normalized_key":"short stable key","confidence":0.0,"action":"create|reinforce|supersede|dispute|ignore","valid_from":null,"valid_until":null,"supersede_normalized_key":null,"source_message_ids":[1],"reason":null}],"profile_candidate":null}',
            'Rules:',
            '- Do not invent user_id. Core assigns identity.',
            '- Extract only durable personal facts, preferences, relationships, project context, standing instructions, explicit "remember this", or changes to already known facts.',
            '- Ignore greetings, ok/thanks, one-off formatting, menu commands, most reminder requests, system/error rows, and temporary UI chatter.',
            '- Do not ingest screenshot visual summaries, ephemeral image descriptions, Storage file contents, or facts scraped from the web as personal memory unless the user explicitly asked to remember a durable personal fact.',
            '- Explicit memory intent such as "запомни" / "remember" should usually become a preference or fact with high confidence.',
            '- Temporary facts must include valid_until when the expiry is known. Do not treat them as eternal profile.',
            '- If a new fact replaces an old one, action=supersede and set supersede_normalized_key.',
            '- If the same fact already exists, action=reinforce. Do not duplicate.',
            '- Use only message ids from the transcript below.',
            '- Confidence is 0..1. Leave memories empty when nothing durable was said.',
        ];

        if ($existingMemories !== []) {
            $lines[] = 'Existing active memories for this user (do not copy blindly):';

            foreach (array_slice($existingMemories, 0, 20) as $memory) {
                $lines[] = '- ['.$memory['kind'].' / '.$memory['key'].' / '.$memory['status'].'] '.$memory['content'];
            }
        }

        $lines[] = 'Transcript:';

        foreach ($messages as $message) {
            $lines[] = sprintf(
                '[id=%d role=%s] %s',
                $message->id,
                $message->role->value,
                trim((string) $message->body),
            );
        }

        return implode("\n", $lines);
    }
}
