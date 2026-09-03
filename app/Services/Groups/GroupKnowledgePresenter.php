<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;

final class GroupKnowledgePresenter
{
    /**
     * @return array{summary: list<array<string, mixed>>, decisions: list<array<string, mixed>>, tasks: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function knowledge(TelegramGroup $group): array
    {
        $rows = TelegramGroupKnowledge::query()
            ->where('telegram_group_id', $group->id)
            ->with(['sources.message'])
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $bucket = [
            'summary' => [],
            'decisions' => [],
            'tasks' => [],
            'events' => [],
        ];

        foreach ($rows as $row) {
            $payload = $this->item($row);
            match ($row->type) {
                TelegramGroupKnowledgeType::Summary => $bucket['summary'][] = $payload,
                TelegramGroupKnowledgeType::Decision => $bucket['decisions'][] = $payload,
                TelegramGroupKnowledgeType::Task => $bucket['tasks'][] = $payload,
                TelegramGroupKnowledgeType::EventFact => $bucket['events'][] = $payload,
            };
        }

        return $bucket;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(TelegramGroup $group): array
    {
        return TelegramGroupAnalysisRun::query()
            ->where('telegram_group_id', $group->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (TelegramGroupAnalysisRun $run): array => [
                'id' => $run->id,
                'analysis_type' => $run->analysis_type->value,
                'from_at' => $run->from_at?->toIso8601String(),
                'to_at' => $run->to_at?->toIso8601String(),
                'status' => $run->status->value,
                'provider' => $run->provider,
                'model' => $run->model,
                'attempts' => (int) $run->attempts,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'last_error' => $run->last_error,
                'counts' => [
                    'summaries' => (int) ($run->metadata['summaries'] ?? 0),
                    'decisions' => (int) ($run->metadata['decisions'] ?? 0),
                    'tasks' => (int) ($run->metadata['tasks'] ?? 0),
                    'events' => (int) ($run->metadata['events'] ?? 0),
                    'chunk_count' => (int) ($run->metadata['chunk_count'] ?? 0),
                ],
                'no_data' => (bool) ($run->metadata['no_data'] ?? false),
                'can_retry' => $run->status->value === 'failed',
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(TelegramGroupKnowledge $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type->value,
            'title' => $row->title,
            'content' => $row->content,
            'structured_data' => $row->structured_data,
            'confidence' => $row->confidence,
            'status' => $row->status->value,
            'generated_at' => $row->generated_at?->toIso8601String(),
            'generated_by_provider' => $row->generated_by_provider,
            'generated_by_model' => $row->generated_by_model,
            'is_active' => $row->status === TelegramGroupKnowledgeStatus::Active,
            'sources' => $row->sources->map(function ($source): array {
                $message = $source->message;

                return [
                    'message_id' => (int) $source->message_id,
                    'occurred_at' => optional($message?->occurred_at)?->toIso8601String(),
                    'sender_name' => $message?->sender_name,
                    'snippet' => mb_substr(trim((string) ($message?->body ?? '')), 0, 140),
                ];
            })->all(),
        ];
    }
}
