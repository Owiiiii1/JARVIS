<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationStatus;
use App\Enums\KnowledgeRelationType;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntityAlias;
use App\Models\KnowledgeEntitySource;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Project;
use App\Models\User;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\Exceptions\KnowledgeException;
use App\Services\Watchers\WatcherEvaluationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class KnowledgeIngestionService
{
    public function __construct(
        private readonly KnowledgeEntityResolver $resolver = new KnowledgeEntityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertEntity(
        User $user,
        KnowledgeEntityType $type,
        string $name,
        KnowledgeSourceRef $source,
        array $attributes = [],
    ): KnowledgeEntity {
        $display = KnowledgeNameNormalizer::displayName($name);
        $normalized = KnowledgeNameNormalizer::name($display);

        if ($display === '' || $normalized === '') {
            throw new KnowledgeException('invalid_name', 'Entity name is required.');
        }

        $projectId = isset($attributes['project_id']) ? (int) $attributes['project_id'] : $source->projectId;
        $aliases = is_array($attributes['aliases'] ?? null) ? $attributes['aliases'] : [];
        $summary = KnowledgeNameNormalizer::summary(isset($attributes['summary']) ? (string) $attributes['summary'] : null);
        $metadata = KnowledgeNameNormalizer::boundMetadata(
            is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
        $confidence = KnowledgeConfidence::clamp((float) ($attributes['confidence'] ?? $source->confidence));

        return DB::transaction(function () use ($user, $type, $display, $normalized, $source, $projectId, $aliases, $summary, $metadata, $confidence): KnowledgeEntity {
            $match = $this->resolver->findMatch(
                $user,
                $type,
                $normalized,
                $aliases,
                $projectId,
                is_string($metadata['external_ref'] ?? null) ? (string) $metadata['external_ref'] : null,
                $confidence,
            );

            if ($match !== null) {
                $this->reinforceEntity($match, $display, $summary, $projectId, $metadata, $confidence);
                $entity = $match->fresh() ?? $match;
            } else {
                $entity = KnowledgeEntity::query()->create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'name' => $display,
                    'normalized_name' => $normalized,
                    'summary' => $summary,
                    'status' => KnowledgeEntityStatus::Active,
                    'project_id' => $this->ownedProjectId($user, $projectId),
                    'confidence' => $confidence,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);
            }

            $this->attachSource($entity, $source);
            $this->upsertAlias($entity, $display, $source, $confidence);

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                $this->upsertAlias($entity, $alias, $source, $confidence);
            }

            return $entity->fresh(['aliases']) ?? $entity;
        });
    }

    public function requireOwnedEntity(User $user, int $entityId): KnowledgeEntity
    {
        $entity = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->whereKey($entityId)
            ->first();

        if ($entity === null) {
            throw new KnowledgeException('not_found', 'Knowledge entity was not found.');
        }

        return $entity;
    }

    public function upsertAlias(
        KnowledgeEntity $entity,
        string $alias,
        KnowledgeSourceRef $source,
        ?float $confidence = null,
    ): KnowledgeEntityAlias {
        $display = KnowledgeNameNormalizer::displayName($alias);
        $normalized = KnowledgeNameNormalizer::name($display);

        $row = KnowledgeEntityAlias::query()->firstOrNew([
            'knowledge_entity_id' => $entity->id,
            'normalized_alias' => $normalized,
        ]);

        $row->fill([
            'user_id' => $entity->user_id,
            'alias' => $display !== '' ? $display : $entity->name,
            'source_type' => $source->type,
            'confidence' => KnowledgeConfidence::clamp($confidence ?? $source->confidence),
        ]);
        $row->save();

        return $row;
    }

    public function upsertRelationship(
        User $user,
        KnowledgeEntity $sourceEntity,
        KnowledgeEntity $targetEntity,
        KnowledgeRelationType $type,
        KnowledgeSourceRef $source,
        ?string $label = null,
        bool $deactivate = false,
    ): KnowledgeRelationship {
        $this->assertSameOwner($user, $sourceEntity);
        $this->assertSameOwner($user, $targetEntity);

        if ($sourceEntity->id === $targetEntity->id) {
            throw new KnowledgeException('invalid_relationship', 'An entity cannot relate to itself.');
        }

        if (! KnowledgeConfidence::isMediumOrHigher($source->confidence) && ! $source->manual && ! $deactivate) {
            throw new KnowledgeException('low_confidence', 'Low-confidence relations are not auto-created.');
        }

        $now = CarbonImmutable::now('UTC');

        $row = KnowledgeRelationship::query()->firstOrNew([
            'user_id' => $user->id,
            'source_entity_id' => $sourceEntity->id,
            'target_entity_id' => $targetEntity->id,
            'type' => $type,
        ]);

        $wasActive = $row->exists && $row->status === KnowledgeRelationStatus::Active;

        if ($deactivate) {
            if (! $row->exists) {
                $row->fill([
                    'label' => KnowledgeNameNormalizer::displayName((string) $label) ?: null,
                    'status' => KnowledgeRelationStatus::Inactive,
                    'confidence' => KnowledgeConfidence::clamp($source->confidence),
                    'valid_from' => $now,
                    'valid_to' => $now,
                    'superseded_at' => $now,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'source_count' => 1,
                    'metadata' => null,
                ]);
                $row->save();
            } elseif ($wasActive) {
                $row->forceFill([
                    'status' => KnowledgeRelationStatus::Inactive,
                    'valid_to' => $now,
                    'superseded_at' => $now,
                    'last_seen_at' => $now,
                    'source_count' => (int) $row->source_count + 1,
                ])->save();
            }

            $this->recordEvent(
                $user,
                KnowledgeEventType::RelationshipSuperseded,
                $sourceEntity->name.' '.$type->value.' '.$targetEntity->name,
                $source,
                [$sourceEntity, $targetEntity],
                $now,
            );

            return $row->fresh() ?? $row;
        }

        $row->fill([
            'label' => $label !== null && trim($label) !== '' ? KnowledgeNameNormalizer::displayName($label) : $row->label,
            'status' => KnowledgeRelationStatus::Active,
            'confidence' => max((float) ($row->confidence ?? 0), KnowledgeConfidence::clamp($source->confidence)),
            'valid_from' => $row->valid_from ?? $now,
            'valid_to' => null,
            'superseded_at' => null,
            'first_seen_at' => $row->first_seen_at ?? $now,
            'last_seen_at' => $now,
            'source_count' => $row->exists ? ((int) $row->source_count + 1) : 1,
        ]);
        $row->save();

        $this->attachSource($sourceEntity, $source);
        $this->attachSource($targetEntity, $source);

        $this->recordEvent(
            $user,
            KnowledgeEventType::KnowledgeLinked,
            $sourceEntity->name.' '.$type->value.' '.$targetEntity->name,
            $source,
            [$sourceEntity, $targetEntity],
            $now,
        );

        return $row->fresh() ?? $row;
    }

    /**
     * @param  list<KnowledgeEntity>  $entities
     * @param  array<int, string>  $roles
     */
    public function recordEvent(
        User $user,
        KnowledgeEventType $type,
        string $title,
        KnowledgeSourceRef $source,
        array $entities = [],
        ?CarbonImmutable $occurredAt = null,
        array $roles = [],
    ): KnowledgeEvent {
        $title = KnowledgeNameNormalizer::displayName($title);

        if ($title === '') {
            $title = $type->value;
        }

        $event = KnowledgeEvent::query()->firstOrNew([
            'user_id' => $user->id,
            'source_fingerprint' => $source->fingerprint,
        ]);

        $created = ! $event->exists;

        if ($created) {
            $event->fill([
                'type' => $type,
                'title' => mb_substr($title, 0, 240),
                'occurred_at' => $occurredAt ?? $source->observedAt ?? CarbonImmutable::now('UTC'),
                'source_type' => $source->type,
                'conversation_id' => $source->conversationId,
                'confidence' => KnowledgeConfidence::clamp($source->confidence),
                'metadata' => KnowledgeNameNormalizer::boundMetadata($this->eventMetadata($source)),
            ]);
            $event->save();
        }

        foreach ($entities as $index => $entity) {
            $this->assertSameOwner($user, $entity);
            $role = $roles[$index] ?? ($index === 0 ? 'subject' : 'related');
            $event->entities()->syncWithoutDetaching([
                $entity->id => ['role' => mb_substr($role, 0, 32)],
            ]);
            $this->attachSource($entity, $source);
        }

        if ($created) {
            try {
                app(WatcherEvaluationDispatcher::class)->afterKnowledgeEvent($user, $event->fresh(['entities']) ?? $event);
            } catch (Throwable) {
            }
        }

        return $event->fresh(['entities']) ?? $event;
    }

    public function attachSource(KnowledgeEntity $entity, KnowledgeSourceRef $source): KnowledgeEntitySource
    {
        $row = KnowledgeEntitySource::query()->firstOrNew([
            'knowledge_entity_id' => $entity->id,
            'source_fingerprint' => $source->fingerprint,
        ]);

        $row->fill([
            'user_id' => $entity->user_id,
            'source_type' => $source->type,
            'conversation_id' => $source->conversationId,
            'message_id' => $source->messageId,
            'memory_id' => $source->memoryId,
            'project_id' => $source->projectId,
            'task_id' => $source->taskId,
            'reminder_id' => $source->reminderId,
            'stored_file_id' => $source->storedFileId,
            'confidence' => KnowledgeConfidence::clamp($source->confidence),
            'observed_at' => $source->observedAt ?? CarbonImmutable::now('UTC'),
        ]);
        $row->save();

        if ($entity->status === KnowledgeEntityStatus::OrphanCandidate) {
            $entity->forceFill(['status' => KnowledgeEntityStatus::Active])->save();
        }

        return $row;
    }

    public function addNote(
        User $user,
        KnowledgeEntity $entity,
        string $note,
        KnowledgeSourceRef $source,
    ): KnowledgeEvent {
        $this->assertSameOwner($user, $entity);
        $summary = KnowledgeNameNormalizer::summary($note);

        if ($summary !== null && ($entity->summary === null || $entity->summary === '')) {
            $entity->forceFill(['summary' => $summary])->save();
        }

        return $this->recordEvent(
            $user,
            KnowledgeEventType::ManualNote,
            $summary ?? 'Note',
            $source,
            [$entity],
        );
    }

    /**
     * @param  array{entities?: list<array<string, mixed>>, relationships?: list<array<string, mixed>>, events?: list<array<string, mixed>>}  $extracted
     * @return array{entities: int, relationships: int, events: int, skipped: int}
     */
    public function applyExtraction(User $user, array $extracted, KnowledgeSourceRef $source): array
    {
        $stats = ['entities' => 0, 'relationships' => 0, 'events' => 0, 'skipped' => 0];
        /** @var array<string, KnowledgeEntity> $created */
        $created = [];

        foreach ($extracted['entities'] ?? [] as $row) {
            if (! is_array($row)) {
                $stats['skipped']++;

                continue;
            }

            $type = KnowledgeEntityType::tryFromLoose($row['type'] ?? null);
            $name = trim((string) ($row['name'] ?? ''));
            $confidence = KnowledgeConfidence::fromLabel(
                isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
                isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
            );
            $kind = mb_strtolower((string) ($row['kind'] ?? $row['fact_kind'] ?? 'explicit'));

            if ($type === null || $name === '' || $kind === 'inference' || ! KnowledgeConfidence::isMediumOrHigher($confidence)) {
                $stats['skipped']++;

                continue;
            }

            if (! KnowledgeConfidence::isHigh($confidence) && $kind !== 'explicit') {
                $stats['skipped']++;

                continue;
            }

            $itemSource = new KnowledgeSourceRef(
                type: $source->type,
                fingerprint: KnowledgeSourceRef::hash($source->fingerprint, 'entity', $type->value, KnowledgeNameNormalizer::name($name)),
                confidence: $confidence,
                conversationId: $source->conversationId,
                messageId: $source->messageId,
                memoryId: $source->memoryId,
                projectId: $source->projectId,
                taskId: $source->taskId,
                reminderId: $source->reminderId,
                storedFileId: $source->storedFileId,
                observedAt: $source->observedAt,
            );

            $entity = $this->upsertEntity($user, $type, $name, $itemSource, [
                'summary' => $row['summary'] ?? null,
                'aliases' => is_array($row['aliases'] ?? null) ? $row['aliases'] : [],
                'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : $source->projectId,
                'confidence' => $confidence,
            ]);
            $key = is_string($row['key'] ?? null) && $row['key'] !== ''
                ? (string) $row['key']
                : $type->value.':'.KnowledgeNameNormalizer::name($name);
            $created[$key] = $entity;
            $created[$type->value.':'.KnowledgeNameNormalizer::name($name)] = $entity;
            $stats['entities']++;
        }

        foreach ($extracted['relationships'] ?? [] as $row) {
            if (! is_array($row)) {
                $stats['skipped']++;

                continue;
            }

            $relationType = KnowledgeRelationType::tryFromLoose($row['type'] ?? null);
            $confidence = KnowledgeConfidence::fromLabel(
                isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
                isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
            );
            $kind = mb_strtolower((string) ($row['kind'] ?? 'explicit'));

            if ($relationType === null || $kind === 'inference' || ! KnowledgeConfidence::isHigh($confidence)) {
                $stats['skipped']++;

                continue;
            }

            $from = $this->resolveExtractedEntity($user, $created, $row['source'] ?? $row['from'] ?? null, $source);
            $to = $this->resolveExtractedEntity($user, $created, $row['target'] ?? $row['to'] ?? null, $source);

            if ($from === null || $to === null) {
                $stats['skipped']++;

                continue;
            }

            $itemSource = new KnowledgeSourceRef(
                type: $source->type,
                fingerprint: KnowledgeSourceRef::hash($source->fingerprint, 'rel', (string) $from->id, $relationType->value, (string) $to->id),
                confidence: $confidence,
                conversationId: $source->conversationId,
                messageId: $source->messageId,
                memoryId: $source->memoryId,
                projectId: $source->projectId,
                observedAt: $source->observedAt,
            );

            $this->upsertRelationship(
                $user,
                $from,
                $to,
                $relationType,
                $itemSource,
                isset($row['label']) ? (string) $row['label'] : null,
                (bool) ($row['deactivate'] ?? false),
            );
            $stats['relationships']++;
        }

        foreach ($extracted['events'] ?? [] as $row) {
            if (! is_array($row)) {
                $stats['skipped']++;

                continue;
            }

            $eventType = KnowledgeEventType::tryFromLoose($row['type'] ?? null);
            $title = trim((string) ($row['title'] ?? ''));
            $confidence = KnowledgeConfidence::fromLabel(
                isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
                isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
            );

            if ($eventType === null || $title === '' || ! KnowledgeConfidence::isHigh($confidence)) {
                $stats['skipped']++;

                continue;
            }

            $related = [];

            foreach ((array) ($row['entities'] ?? []) as $ref) {
                $entity = $this->resolveExtractedEntity($user, $created, $ref, $source);

                if ($entity !== null) {
                    $related[] = $entity;
                }
            }

            $itemSource = new KnowledgeSourceRef(
                type: $source->type,
                fingerprint: KnowledgeSourceRef::hash($source->fingerprint, 'event', $eventType->value, KnowledgeNameNormalizer::name($title)),
                confidence: $confidence,
                conversationId: $source->conversationId,
                memoryId: $source->memoryId,
                observedAt: $source->observedAt,
            );

            $this->recordEvent($user, $eventType, $title, $itemSource, $related);
            $stats['events']++;
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function reinforceEntity(
        KnowledgeEntity $entity,
        string $display,
        ?string $summary,
        ?int $projectId,
        array $metadata,
        float $confidence,
    ): void {
        $next = [
            'confidence' => max((float) $entity->confidence, $confidence),
            'status' => KnowledgeEntityStatus::Active,
        ];

        if ($entity->name === '' || $display !== '' && mb_strlen($display) > mb_strlen((string) $entity->name)) {
            $next['name'] = $display;
        }

        if ($summary !== null && ($entity->summary === null || $entity->summary === '')) {
            $next['summary'] = $summary;
        }

        if ($entity->project_id === null && $projectId !== null) {
            $owner = User::query()->find($entity->user_id);
            $next['project_id'] = $owner !== null
                ? ($this->ownedProjectId($owner, $projectId) ?? $entity->project_id)
                : $entity->project_id;
        }

        if ($metadata !== []) {
            $next['metadata'] = KnowledgeNameNormalizer::boundMetadata(array_merge($entity->metadata ?? [], $metadata));
        }

        $entity->forceFill($next)->save();
    }

    /**
     * @param  array<string, KnowledgeEntity>  $created
     */
    private function resolveExtractedEntity(
        User $user,
        array $created,
        mixed $ref,
        KnowledgeSourceRef $source,
    ): ?KnowledgeEntity {
        if (is_array($ref)) {
            $key = isset($ref['key']) ? (string) $ref['key'] : '';
            $name = trim((string) ($ref['name'] ?? ''));
            $type = KnowledgeEntityType::tryFromLoose($ref['type'] ?? null);

            if ($key !== '' && isset($created[$key])) {
                return $created[$key];
            }

            if ($type !== null && $name !== '') {
                $lookup = $type->value.':'.KnowledgeNameNormalizer::name($name);

                if (isset($created[$lookup])) {
                    return $created[$lookup];
                }

                return $this->resolver->findMatch($user, $type, KnowledgeNameNormalizer::name($name), [], $source->projectId, null, $source->confidence);
            }

            return null;
        }

        if (! is_string($ref) || trim($ref) === '') {
            return null;
        }

        $key = trim($ref);

        if (isset($created[$key])) {
            return $created[$key];
        }

        $normalized = KnowledgeNameNormalizer::name($key);

        return $this->resolver->findByNormalizedName($user, $normalized);
    }

    private function ownedProjectId(User $user, ?int $projectId): ?int
    {
        if ($projectId === null || $projectId <= 0) {
            return null;
        }

        $exists = Project::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->exists();

        return $exists ? $projectId : null;
    }

    private function assertSameOwner(User $user, KnowledgeEntity $entity): void
    {
        if ((int) $entity->user_id !== (int) $user->id) {
            throw new KnowledgeException('not_found', 'Knowledge entity was not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventMetadata(KnowledgeSourceRef $source): array
    {
        $metadata = [];

        foreach ([
            'conversation_id' => $source->conversationId,
            'message_id' => $source->messageId,
            'memory_id' => $source->memoryId,
            'project_id' => $source->projectId,
            'task_id' => $source->taskId,
            'reminder_id' => $source->reminderId,
            'stored_file_id' => $source->storedFileId,
        ] as $key => $value) {
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }
}
