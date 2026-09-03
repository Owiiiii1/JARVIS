<?php

namespace App\Services\Memory;

use App\Enums\MemoryAction;
use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemorySourceKind;
use App\Enums\MemoryStatus;
use App\Models\Memory;
use App\Models\MemoryRevision;
use App\Models\MemorySource;
use App\Models\Message;
use App\Models\User;
use App\Services\Memory\DTO\MemoryAnalysisResult;
use App\Services\Memory\DTO\MemoryCandidate;
use App\Services\Memory\DTO\MemoryWriteStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MemoryWriter
{
    public function __construct(
        private readonly TopicService $topics,
    ) {}

    /**
     * @param  list<int>  $fallbackMessageIds
     */
    public function apply(
        User $user,
        int $conversationId,
        MemoryAnalysisResult $result,
        array $fallbackMessageIds,
    ): MemoryWriteStats {
        return DB::transaction(function () use ($user, $conversationId, $result, $fallbackMessageIds): MemoryWriteStats {
            $stats = new MemoryWriteStats;
            $topicCount = $this->topics->apply($user, $result->topics, $fallbackMessageIds);
            $stats = new MemoryWriteStats(topics: $topicCount);

            foreach ($result->memories as $candidate) {
                $stats = $this->applyCandidate($user, $conversationId, $candidate, $fallbackMessageIds, $stats);
            }

            return $stats;
        });
    }

    /**
     * @param  list<int>  $fallbackMessageIds
     */
    private function applyCandidate(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        array $fallbackMessageIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $action = MemoryAction::from($candidate->action);
        $kind = MemoryKind::from($candidate->kind);
        $key = MemoryKeyNormalizer::memoryKey($candidate->normalizedKey, $candidate->content);
        $sourceIds = $candidate->sourceMessageIds !== [] ? $candidate->sourceMessageIds : $fallbackMessageIds;
        $sourceIds = $this->ownedMessageIds($user, $sourceIds);

        if ($sourceIds === [] && $action !== MemoryAction::Ignore) {
            return $stats->withIgnored();
        }

        return match ($action) {
            MemoryAction::Ignore => $stats->withIgnored(),
            MemoryAction::Create => $this->createOrReinforce($user, $conversationId, $candidate, $kind, $key, $sourceIds, $stats),
            MemoryAction::Reinforce => $this->reinforce($user, $conversationId, $candidate, $kind, $key, $sourceIds, $stats),
            MemoryAction::Supersede => $this->supersede($user, $conversationId, $candidate, $kind, $key, $sourceIds, $stats),
            MemoryAction::Dispute => $this->dispute($user, $conversationId, $candidate, $kind, $key, $sourceIds, $stats),
        };
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function createOrReinforce(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        MemoryKind $kind,
        string $key,
        array $sourceIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $existing = $this->findActive($user, $kind, $key);

        if ($existing !== null) {
            return $this->reinforceExisting($existing, $conversationId, $candidate, $sourceIds, $stats);
        }

        $this->createMemory($user, $conversationId, $candidate, $kind, $key, $sourceIds, MemoryStatus::Active);

        return $stats->withCreated();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function reinforce(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        MemoryKind $kind,
        string $key,
        array $sourceIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $existing = $this->findActive($user, $kind, $key);

        if ($existing === null) {
            $this->createMemory($user, $conversationId, $candidate, $kind, $key, $sourceIds, MemoryStatus::Active);

            return $stats->withCreated();
        }

        return $this->reinforceExisting($existing, $conversationId, $candidate, $sourceIds, $stats);
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function supersede(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        MemoryKind $kind,
        string $key,
        array $sourceIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $lookupKey = MemoryKeyNormalizer::memoryKey(
            $candidate->supersedeNormalizedKey ?: $key,
            $candidate->content,
        );
        $old = $this->findActive($user, $kind, $lookupKey) ?? $this->findActive($user, $kind, $key);

        if ($old === null) {
            $this->createMemory($user, $conversationId, $candidate, $kind, $key, $sourceIds, MemoryStatus::Active);

            return $stats->withCreated();
        }

        $previousContent = $old->content;
        $previousStatus = $old->status->value;
        $new = $this->createMemory($user, $conversationId, $candidate, $kind, $key, $sourceIds, MemoryStatus::Active);

        $old->forceFill([
            'status' => MemoryStatus::Superseded,
        ])->save();

        MemoryRevision::query()->create([
            'memory_id' => $old->id,
            'previous_content' => $previousContent,
            'new_content' => $new->content,
            'previous_status' => $previousStatus,
            'new_status' => MemoryStatus::Superseded->value,
            'reason' => $candidate->reason ?: 'superseded_by_new_fact',
            'source_message_id' => $sourceIds[0] ?? null,
        ]);

        MemoryRevision::query()->create([
            'memory_id' => $new->id,
            'previous_content' => $previousContent,
            'new_content' => $new->content,
            'previous_status' => $previousStatus,
            'new_status' => MemoryStatus::Active->value,
            'reason' => $candidate->reason ?: 'replaces_previous_fact',
            'source_message_id' => $sourceIds[0] ?? null,
        ]);

        return $stats->withSuperseded();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function dispute(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        MemoryKind $kind,
        string $key,
        array $sourceIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $existing = $this->findActive($user, $kind, $key);

        if ($existing === null) {
            return $stats->withIgnored();
        }

        $previous = $existing->status->value;
        $existing->forceFill(['status' => MemoryStatus::Disputed])->save();
        $this->attachSources($existing, $conversationId, $sourceIds);

        MemoryRevision::query()->create([
            'memory_id' => $existing->id,
            'previous_content' => $existing->content,
            'new_content' => $existing->content,
            'previous_status' => $previous,
            'new_status' => MemoryStatus::Disputed->value,
            'reason' => $candidate->reason ?: 'disputed_by_analysis',
            'source_message_id' => $sourceIds[0] ?? null,
        ]);

        return $stats->withDisputed();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function reinforceExisting(
        Memory $memory,
        int $conversationId,
        MemoryCandidate $candidate,
        array $sourceIds,
        MemoryWriteStats $stats,
    ): MemoryWriteStats {
        $memory->forceFill([
            'last_confirmed_at' => now(),
            'confidence' => max((float) $memory->confidence, $candidate->confidence),
        ])->save();

        $this->attachSources($memory, $conversationId, $sourceIds);

        return $stats->withReinforced();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function createMemory(
        User $user,
        int $conversationId,
        MemoryCandidate $candidate,
        MemoryKind $kind,
        string $key,
        array $sourceIds,
        MemoryStatus $status,
    ): Memory {
        $memory = Memory::query()->create([
            'user_id' => $user->id,
            'scope' => MemoryScope::Personal,
            'kind' => $kind,
            'content' => $candidate->content,
            'normalized_key' => $key,
            'confidence' => $candidate->confidence,
            'status' => $status,
            'valid_from' => $candidate->validFrom ? CarbonImmutable::parse($candidate->validFrom) : null,
            'valid_until' => $candidate->validUntil ? CarbonImmutable::parse($candidate->validUntil) : null,
            'first_seen_at' => now(),
            'last_confirmed_at' => now(),
        ]);

        $this->attachSources($memory, $conversationId, $sourceIds);

        return $memory;
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function attachSources(Memory $memory, int $conversationId, array $sourceIds): void
    {
        foreach ($sourceIds as $messageId) {
            $exists = MemorySource::query()
                ->where('memory_id', $memory->id)
                ->where('message_id', $messageId)
                ->exists();

            if ($exists) {
                continue;
            }

            MemorySource::query()->create([
                'memory_id' => $memory->id,
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'source_kind' => MemorySourceKind::DirectConversation,
            ]);
        }
    }

    private function findActive(User $user, MemoryKind $kind, string $key): ?Memory
    {
        return Memory::query()
            ->where('user_id', $user->id)
            ->where('scope', MemoryScope::Personal)
            ->where('kind', $kind)
            ->where('normalized_key', $key)
            ->where('status', MemoryStatus::Active)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ownedMessageIds(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Message::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
