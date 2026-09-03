<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use App\Models\Message;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use App\Models\TelegramGroupKnowledgeRevision;
use App\Models\TelegramGroupKnowledgeSource;
use App\Services\Groups\DTO\GroupAnalysisResult;
use App\Services\Groups\DTO\GroupDecisionCandidate;
use App\Services\Groups\DTO\GroupEventCandidate;
use App\Services\Groups\DTO\GroupTaskCandidate;
use App\Services\Memory\MemoryKeyNormalizer;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class GroupKnowledgeWriter
{
    /**
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    public function persist(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        GroupAnalysisResult $result,
        DateTimeZone $timezone,
        string $provider,
        string $model,
    ): array {
        return DB::transaction(function () use ($group, $run, $result, $timezone, $provider, $model): array {
            $stats = [
                'summaries' => 0,
                'decisions' => 0,
                'tasks' => 0,
                'events' => 0,
                'reinforced' => 0,
                'superseded' => 0,
            ];

            if ($result->summary !== null) {
                $stats = $this->writeSummary($group, $run, $result->summary->content, $result->summary->confidence, $result->summary->sourceMessageIds, $provider, $model, $stats);
            }

            foreach ($result->decisions as $decision) {
                $stats = $this->writeDecision($group, $run, $decision, $timezone, $provider, $model, $stats);
            }

            foreach ($result->tasks as $task) {
                $stats = $this->writeTask($group, $run, $task, $timezone, $provider, $model, $stats);
            }

            foreach ($result->events as $event) {
                $stats = $this->writeEvent($group, $run, $event, $timezone, $provider, $model, $stats);
            }

            return $stats;
        });
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}  $stats
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    private function writeSummary(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        string $content,
        float $confidence,
        array $sourceIds,
        string $provider,
        string $model,
        array $stats,
    ): array {
        $key = 'summary:'.$run->from_at->getTimestamp().':'.$run->to_at->getTimestamp();
        $existing = $this->findActive($group, TelegramGroupKnowledgeType::Summary, $key);

        if ($existing !== null) {
            $this->supersedeExisting($existing, $content, 'Updated summary for the same range.');
            $stats['superseded']++;
        }

        $this->createRow(
            group: $group,
            run: $run,
            type: TelegramGroupKnowledgeType::Summary,
            content: $content,
            confidence: $confidence,
            key: $key,
            sourceIds: $sourceIds,
            structured: [
                'range' => [
                    'from' => $run->from_at->toIso8601String(),
                    'to' => $run->to_at->toIso8601String(),
                ],
            ],
            provider: $provider,
            model: $model,
            supersedesId: $existing?->id,
        );
        $stats['summaries']++;

        return $stats;
    }

    /**
     * @param  array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}  $stats
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    private function writeDecision(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        GroupDecisionCandidate $decision,
        DateTimeZone $timezone,
        string $provider,
        string $model,
        array $stats,
    ): array {
        $key = MemoryKeyNormalizer::memoryKey(null, $decision->content);

        return $this->writeTyped(
            group: $group,
            run: $run,
            type: TelegramGroupKnowledgeType::Decision,
            key: $key,
            content: $decision->content,
            confidence: $decision->confidence,
            sourceIds: $decision->sourceMessageIds,
            structured: array_filter([
                'decision' => $decision->content,
                'participants' => $decision->participants,
                'effective_date' => $this->localToUtc($decision->effectiveDateLocal, $timezone)?->toIso8601String(),
                'thread_id' => $decision->threadId,
            ], static fn ($value) => $value !== null && $value !== []),
            supersedesKey: $decision->supersedesNormalizedKey,
            provider: $provider,
            model: $model,
            stats: $stats,
            counter: 'decisions',
        );
    }

    /**
     * @param  array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}  $stats
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    private function writeTask(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        GroupTaskCandidate $task,
        DateTimeZone $timezone,
        string $provider,
        string $model,
        array $stats,
    ): array {
        $key = MemoryKeyNormalizer::memoryKey(null, $task->content.' '.(string) $task->assigneeText);
        $dueAt = $this->localToUtc($task->dueAtLocal, $timezone);

        return $this->writeTyped(
            group: $group,
            run: $run,
            type: TelegramGroupKnowledgeType::Task,
            key: $key,
            content: $task->content,
            confidence: $task->confidence,
            sourceIds: $task->sourceMessageIds,
            structured: array_filter([
                'task' => $task->content,
                'assignee_text' => $task->assigneeText,
                'due_at' => $dueAt?->toIso8601String(),
                'due_at_local' => $task->dueAtLocal,
                'status' => $task->statusHint ?: 'open',
                'thread_id' => $task->threadId,
            ], static fn ($value) => $value !== null && $value !== ''),
            supersedesKey: $task->supersedesNormalizedKey,
            provider: $provider,
            model: $model,
            stats: $stats,
            counter: 'tasks',
            validUntil: $dueAt,
        );
    }

    /**
     * @param  array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}  $stats
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    private function writeEvent(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        GroupEventCandidate $event,
        DateTimeZone $timezone,
        string $provider,
        string $model,
        array $stats,
    ): array {
        $key = MemoryKeyNormalizer::memoryKey(null, $event->content);

        return $this->writeTyped(
            group: $group,
            run: $run,
            type: TelegramGroupKnowledgeType::EventFact,
            key: $key,
            content: $event->content,
            confidence: $event->confidence,
            sourceIds: $event->sourceMessageIds,
            structured: array_filter([
                'event' => $event->content,
                'occurred_at_local' => $event->occurredAtLocal,
                'occurred_at' => $this->localToUtc($event->occurredAtLocal, $timezone)?->toIso8601String(),
                'thread_id' => $event->threadId,
            ], static fn ($value) => $value !== null && $value !== ''),
            supersedesKey: $event->supersedesNormalizedKey,
            provider: $provider,
            model: $model,
            stats: $stats,
            counter: 'events',
        );
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array<string, mixed>  $structured
     * @param  array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}  $stats
     * @return array{summaries: int, decisions: int, tasks: int, events: int, reinforced: int, superseded: int}
     */
    private function writeTyped(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        TelegramGroupKnowledgeType $type,
        string $key,
        string $content,
        float $confidence,
        array $sourceIds,
        array $structured,
        ?string $supersedesKey,
        string $provider,
        string $model,
        array $stats,
        string $counter,
        ?CarbonImmutable $validUntil = null,
    ): array {
        if ($supersedesKey !== null && $supersedesKey !== '') {
            $old = $this->findActive($group, $type, $supersedesKey);

            if ($old !== null && $old->normalized_key !== $key) {
                $this->supersedeExisting($old, $content, 'Superseded by later group analysis.');
                $stats['superseded']++;
                $this->createRow($group, $run, $type, $content, $confidence, $key, $sourceIds, $structured, $provider, $model, $old->id, $validUntil);
                $stats[$counter]++;

                return $stats;
            }
        }

        $existing = $this->findActive($group, $type, $key);

        if ($existing !== null) {
            $this->reinforce($existing, $content, $confidence, $sourceIds, $structured, $provider, $model);
            $stats['reinforced']++;

            return $stats;
        }

        $this->createRow($group, $run, $type, $content, $confidence, $key, $sourceIds, $structured, $provider, $model, null, $validUntil);
        $stats[$counter]++;

        return $stats;
    }

    private function findActive(TelegramGroup $group, TelegramGroupKnowledgeType $type, string $key): ?TelegramGroupKnowledge
    {
        return TelegramGroupKnowledge::query()
            ->where('telegram_group_id', $group->id)
            ->where('type', $type)
            ->where('normalized_key', $key)
            ->where('status', TelegramGroupKnowledgeStatus::Active)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array<string, mixed>  $structured
     */
    private function createRow(
        TelegramGroup $group,
        TelegramGroupAnalysisRun $run,
        TelegramGroupKnowledgeType $type,
        string $content,
        float $confidence,
        string $key,
        array $sourceIds,
        array $structured,
        string $provider,
        string $model,
        ?int $supersedesId = null,
        ?CarbonImmutable $validUntil = null,
    ): TelegramGroupKnowledge {
        $sourceIds = $this->ownedSourceIds($group, $sourceIds);
        [$fromId, $toId] = $this->rangeIds($sourceIds);

        $row = TelegramGroupKnowledge::query()->create([
            'telegram_group_id' => $group->id,
            'analysis_run_id' => $run->id,
            'type' => $type,
            'content' => $content,
            'structured_data' => $structured === [] ? null : $structured,
            'confidence' => $confidence,
            'status' => TelegramGroupKnowledgeStatus::Active,
            'normalized_key' => $key,
            'valid_until' => $validUntil,
            'source_from_message_id' => $fromId,
            'source_to_message_id' => $toId,
            'supersedes_id' => $supersedesId,
            'generated_by_provider' => $provider,
            'generated_by_model' => $model,
            'generated_at' => now(),
        ]);

        $this->attachSources($row, $sourceIds);

        return $row;
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array<string, mixed>  $structured
     */
    private function reinforce(
        TelegramGroupKnowledge $existing,
        string $content,
        float $confidence,
        array $sourceIds,
        array $structured,
        string $provider,
        string $model,
    ): void {
        $sourceIds = $this->ownedSourceIds($existing->group, $sourceIds);
        $previous = $existing->content;
        $merged = array_merge($existing->structured_data ?? [], $structured);
        [$fromId, $toId] = $this->rangeIds($this->existingAndNewIds($existing, $sourceIds));

        $existing->forceFill([
            'content' => $content,
            'structured_data' => $merged === [] ? $existing->structured_data : $merged,
            'confidence' => max((float) $existing->confidence, $confidence),
            'source_from_message_id' => $fromId ?? $existing->source_from_message_id,
            'source_to_message_id' => $toId ?? $existing->source_to_message_id,
            'generated_by_provider' => $provider,
            'generated_by_model' => $model,
            'generated_at' => now(),
        ])->save();

        $this->attachSources($existing, $sourceIds);

        if ($previous !== $content) {
            TelegramGroupKnowledgeRevision::query()->create([
                'knowledge_id' => $existing->id,
                'previous_content' => $previous,
                'new_content' => $content,
                'previous_status' => $existing->status->value,
                'new_status' => $existing->status->value,
                'reason' => 'Reinforced by overlapping analysis.',
                'created_at' => now(),
            ]);
        }
    }

    private function supersedeExisting(TelegramGroupKnowledge $existing, string $newContent, string $reason): void
    {
        $previousStatus = $existing->status->value;
        $existing->forceFill([
            'status' => TelegramGroupKnowledgeStatus::Superseded,
        ])->save();

        TelegramGroupKnowledgeRevision::query()->create([
            'knowledge_id' => $existing->id,
            'previous_content' => $existing->content,
            'new_content' => $newContent,
            'previous_status' => $previousStatus,
            'new_status' => TelegramGroupKnowledgeStatus::Superseded->value,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<int>
     */
    private function ownedSourceIds(TelegramGroup $group, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return Message::query()
            ->where('telegram_group_id', $group->id)
            ->whereIn('id', $sourceIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function attachSources(TelegramGroupKnowledge $row, array $sourceIds): void
    {
        foreach ($sourceIds as $messageId) {
            TelegramGroupKnowledgeSource::query()->firstOrCreate(
                [
                    'knowledge_id' => $row->id,
                    'message_id' => $messageId,
                ],
                [
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  list<int>  $sourceIds
     * @return array{0: int|null, 1: int|null}
     */
    private function rangeIds(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [null, null];
        }

        return [min($sourceIds), max($sourceIds)];
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<int>
     */
    private function existingAndNewIds(TelegramGroupKnowledge $existing, array $sourceIds): array
    {
        $ids = $existing->sources()->pluck('message_id')->map(static fn ($id): int => (int) $id)->all();

        return array_values(array_unique(array_merge($ids, $sourceIds)));
    }

    private function localToUtc(?string $local, DateTimeZone $timezone): ?CarbonImmutable
    {
        if ($local === null || $local === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $local) === 1) {
                return CarbonImmutable::createFromFormat('Y-m-d', $local, $timezone)->startOfDay()->setTimezone('UTC');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $local) === 1) {
                $normalized = str_replace('T', ' ', $local);

                return CarbonImmutable::createFromFormat('Y-m-d H:i', $normalized, $timezone)->setTimezone('UTC');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $local) === 1) {
                $normalized = str_replace('T', ' ', $local);

                return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $timezone)->setTimezone('UTC');
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
