<?php

namespace App\Services\Synthesis;

use App\Enums\CommitmentStatus;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationType;
use App\Enums\TaskStatus;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Task;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Workspace\Presentation\HumanRelationLabel;
use Carbon\CarbonImmutable;

final class CommitmentResolver
{
    /**
     * @return list<SynthesisItem>
     */
    public function resolve(FactPack $pack, string $mode = 'all'): array
    {
        $items = [];
        $tasksById = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $tasksById[(int) $task->id] = $task;
            }
        }

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent || $event->type !== KnowledgeEventType::CommitmentMade) {
                continue;
            }

            $metadata = $event->metadata ?? [];
            $title = (string) $event->title;
            $kind = mb_strtolower((string) ($metadata['kind'] ?? 'explicit'));

            if ($kind === 'inference' || CommitmentLanguage::isVague($title) || ! $this->isExplicit($title, $metadata)) {
                continue;
            }

            $status = CommitmentStatus::tryFromLoose($metadata['status'] ?? 'open') ?? CommitmentStatus::Open;
            $taskId = isset($metadata['task_id']) ? (int) $metadata['task_id'] : null;
            $linked = $taskId !== null ? ($tasksById[$taskId] ?? Task::query()->find($taskId)) : null;

            if ($linked instanceof Task && $linked->status === TaskStatus::Completed && $status === CommitmentStatus::Open) {
                $status = CommitmentStatus::Fulfilled;
            }

            if ($status !== CommitmentStatus::Open) {
                continue;
            }

            $side = $this->side($title, $metadata);

            if ($mode === 'mine' && $side !== 'mine') {
                continue;
            }

            if ($mode === 'others' && $side !== 'others') {
                continue;
            }

            $since = $event->occurred_at instanceof CarbonImmutable ? $event->occurred_at : $pack->now;
            $due = isset($metadata['due_at']) ? (string) $metadata['due_at'] : null;
            $items[] = new SynthesisItem(
                kind: 'commitment',
                title: $title,
                why: $side === 'mine' ? 'Вы это пообещали.' : 'Это пообещали вам.',
                since: $since->toIso8601String(),
                dueAt: $due,
                score: $due !== '' && $due !== null ? 30 : 10,
                sources: [new SourceRef(
                    knowledgeEventId: (int) $event->id,
                    conversationId: $event->conversation_id,
                    taskId: $taskId,
                    sourceFingerprint: $event->source_fingerprint,
                    domain: 'knowledge',
                )],
                extra: [
                    'actor' => $side === 'mine' ? 'user' : (string) ($metadata['actor'] ?? 'other'),
                    'side' => $side,
                    'action' => (string) ($metadata['action'] ?? $title),
                    'status' => $status->value,
                    'confidence' => $event->confidence,
                ],
                fingerprint: 'commitment:event:'.$event->id,
            );
        }

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship || $relation->type !== KnowledgeRelationType::CommittedTo) {
                continue;
            }

            $source = $relation->sourceEntity;
            $target = $relation->targetEntity;
            $title = HumanRelationLabel::sentence(
                KnowledgeRelationType::CommittedTo,
                $source?->name ?? 'Кто-то',
                $target?->name ?? 'выполнить работу',
            );
            $items[] = new SynthesisItem(
                kind: 'commitment',
                title: $title,
                why: 'Это пообещали вам.',
                sources: [new SourceRef(entityId: (int) $relation->source_entity_id, domain: 'knowledge')],
                extra: [
                    'side' => 'others',
                    'status' => CommitmentStatus::Open->value,
                ],
                fingerprint: 'commitment:relation:'.$relation->id,
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function isExplicit(string $title, array $metadata): bool
    {
        if (($metadata['explicit'] ?? false) === true) {
            return true;
        }

        return CommitmentLanguage::isExplicit($title);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function side(string $title, array $metadata): string
    {
        $side = mb_strtolower((string) ($metadata['side'] ?? $metadata['actor'] ?? ''));

        if (in_array($side, ['mine', 'user', 'me'], true)) {
            return 'mine';
        }

        if (in_array($side, ['others', 'other', 'them'], true)) {
            return 'others';
        }

        return CommitmentLanguage::actorIsUser($title) ? 'mine' : 'others';
    }
}
