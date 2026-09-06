<?php

namespace App\Services\Knowledge;

final class KnowledgeExtractionPromptBuilder
{
    public function systemPrompt(): string
    {
        return implode("\n", [
            'Extract structured knowledge from the supplied source text.',
            'Return JSON only. No markdown. No chain-of-thought.',
            'Schema:',
            '{"entities":[{"key":"stable-key","type":"person|project|organization|product|place|topic|system|file|custom","name":"string","summary":"string|null","aliases":["string"],"kind":"explicit|inference","confidence":0.0,"confidence_label":"high|medium|low"}],"relationships":[{"type":"works_on|works_for|uses|depends_on|has_resource|mentioned_in|client_of|related_to|owns|participates_in|waiting_on|committed_to","source":{"key":"stable-key"},"target":{"key":"stable-key"},"label":null,"deactivate":false,"kind":"explicit|inference","confidence":0.0,"confidence_label":"high|medium|low"}],"events":[{"type":"conversation_mentioned|manual_note|task_created|task_completed|project_created|commitment_made","title":"string","entities":[{"key":"stable-key"}],"kind":"explicit|inference","confidence":0.0,"actor":"user|other|null","side":"mine|others|null","action":"string|null","due_at":"ISO-8601|null"}]}',
            'Rules:',
            '- Extract only explicit facts present in the source.',
            '- kind=inference for guesses ("probably", "наверное"). Do not treat inference as fact.',
            '- commitment_made only for explicit promises (“I’ll send it Friday”, “Marco обещал прислать”). Never from vague phrases (“надо бы”, “maybe”).',
            '- Do not invent contact details, religion, politics, health, sexuality, or ethnicity.',
            '- Do not copy secrets, passwords, tokens, or full email bodies.',
            '- Summaries stay short. Empty arrays are allowed.',
            '- Confidence high only when the source states the fact clearly.',
        ]);
    }

    public function userPrompt(string $sourceType, string $text): string
    {
        $max = max(200, (int) config('knowledge.max_source_chars', 4000));
        $text = mb_substr(trim($text), 0, $max);

        return "Source type: {$sourceType}\n\nSource text:\n".$text;
    }
}
