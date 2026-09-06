<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\KnowledgeEventType;
use App\Enums\WatcherTriggerType;
use App\Models\KnowledgeEvent;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class KnowledgeWatcherSource implements WatcherSourceAdapter
{
    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::KnowledgeEvent;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $config = is_array($watcher->source_config) ? $watcher->source_config : [];
        $since = $watcher->last_checked_at ?? CarbonImmutable::now('UTC')->subMinutes(15);
        $query = KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('watchers.limits.max_observations', 20)));

        $entityId = (int) ($watcher->knowledge_entity_id ?? ($config['knowledge_entity_id'] ?? 0));
        if ($entityId > 0) {
            $query->whereHas('entities', fn ($inner) => $inner->where('knowledge_entities.id', $entityId));
        }

        $projectId = (int) ($watcher->project_id ?? ($config['project_id'] ?? 0));
        if ($projectId > 0) {
            $query->where(function ($inner) use ($projectId): void {
                $inner->where('metadata->project_id', $projectId)
                    ->orWhereHas('entities', fn ($entities) => $entities->where('knowledge_entities.project_id', $projectId));
            });
        }

        $type = KnowledgeEventType::tryFromLoose($config['event_type'] ?? ($watcher->condition_config['event_type'] ?? null));
        if ($type !== null) {
            $query->where('type', $type);
        }

        $observations = [];
        foreach ($query->get() as $event) {
            $observations[] = new WatcherObservation(
                sourceType: 'knowledge_event',
                sourceId: (string) $event->id,
                eventType: $event->type->value,
                fingerprint: WatcherSupport::fingerprint('knowledge', (string) $watcher->id, (string) $event->id),
                occurredAt: $event->occurred_at instanceof CarbonImmutable ? $event->occurred_at : CarbonImmutable::parse((string) $event->occurred_at)->utc(),
                title: (string) $event->title,
                metadata: ['source_type' => $event->source_type->value],
                entityId: $entityId > 0 ? $entityId : null,
                projectId: $projectId > 0 ? $projectId : null,
                knowledgeEventId: (int) $event->id,
            );
        }

        return $observations;
    }
}
