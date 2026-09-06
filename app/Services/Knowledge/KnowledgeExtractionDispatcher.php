<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeSourceType;
use App\Jobs\ExtractKnowledgeFromSourceJob;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\User;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Memory\DTO\MemoryWriteStats;
use App\Services\Users\UserCapability;

final class KnowledgeExtractionDispatcher
{
    public function afterMemoryWrite(User $user, MemoryWriteStats $stats, int $conversationId): void
    {
        if (! (bool) config('knowledge.extract_from_memory', true)) {
            return;
        }

        if ($stats->created + $stats->reinforced + $stats->superseded < 1) {
            return;
        }

        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return;
        }

        $memory = Memory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($memory === null) {
            return;
        }

        ExtractKnowledgeFromSourceJob::dispatch(
            (int) $user->id,
            KnowledgeSourceType::Memory->value,
            KnowledgeSourceRef::hash('memory', (string) $memory->id, (string) $memory->updated_at),
            (int) $memory->id,
            $conversationId,
        );
    }

    public function afterSummary(User $user, ConversationSummary $summary): void
    {
        if (! (bool) config('knowledge.extract_from_summary', true)) {
            return;
        }

        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return;
        }

        ExtractKnowledgeFromSourceJob::dispatch(
            (int) $user->id,
            KnowledgeSourceType::Summary->value,
            KnowledgeSourceRef::hash('summary', (string) $summary->id, (string) $summary->version),
            (int) $summary->id,
            (int) $summary->conversation_id,
        );
    }
}
