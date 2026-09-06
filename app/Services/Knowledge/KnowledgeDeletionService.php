<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntitySource;
use App\Models\KnowledgeEvent;
use App\Models\User;

final class KnowledgeDeletionService
{
    public function detachConversation(User $user, int $conversationId): void
    {
        KnowledgeEntitySource::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversationId)
            ->update([
                'conversation_id' => null,
                'message_id' => null,
            ]);

        KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversationId)
            ->update(['conversation_id' => null]);

        $this->markOrphans($user);
    }

    public function markOrphans(User $user): void
    {
        $ids = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('status', KnowledgeEntityStatus::Active)
            ->pluck('id');

        foreach ($ids as $id) {
            $sources = KnowledgeEntitySource::query()
                ->where('knowledge_entity_id', $id)
                ->get();

            if ($sources->isEmpty()) {
                KnowledgeEntity::query()->whereKey($id)->update([
                    'status' => KnowledgeEntityStatus::OrphanCandidate,
                ]);

                continue;
            }

            $hasSupport = $sources->contains(
                fn (KnowledgeEntitySource $source): bool => $source->source_type === KnowledgeSourceType::Manual
                    || $source->hasLiveReference(),
            );

            if (! $hasSupport) {
                KnowledgeEntity::query()->whereKey($id)->update([
                    'status' => KnowledgeEntityStatus::OrphanCandidate,
                ]);
            }
        }
    }
}
