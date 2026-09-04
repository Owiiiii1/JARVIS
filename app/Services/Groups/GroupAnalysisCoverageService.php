<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupKnowledgeStatus;
use App\Models\Message;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use Carbon\CarbonImmutable;

final class GroupAnalysisCoverageService
{
    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     * @return array{
     *     raw_messages: int,
     *     analysis_runs: int,
     *     knowledge_items: int,
     *     latest_completed_at: string|null,
     *     status: string,
     *     queued: bool,
     *     stale: bool
     * }
     */
    public function coverage(TelegramGroup $group, ?array $range): array
    {
        $rawCount = $this->rawCount($group, $range);
        $knowledgeCount = $this->knowledgeCount($group, $range);

        $queued = TelegramGroupAnalysisRun::query()
            ->where('telegram_group_id', $group->id)
            ->when($range !== null, function ($query) use ($range): void {
                $query->where('from_at', $range['from'])->where('to_at', $range['to']);
            })
            ->whereIn('status', [
                TelegramGroupAnalysisRunStatus::Queued,
                TelegramGroupAnalysisRunStatus::Processing,
            ])
            ->exists();

        $completed = TelegramGroupAnalysisRun::query()
            ->where('telegram_group_id', $group->id)
            ->when($range !== null, function ($query) use ($range): void {
                $query->where(function ($builder) use ($range): void {
                    $builder->where(function ($exact) use ($range): void {
                        $exact->where('from_at', $range['from'])->where('to_at', $range['to']);
                    })->orWhere(function ($covers) use ($range): void {
                        $covers->where('from_at', '<=', $range['from'])->where('to_at', '>=', $range['to']);
                    });
                });
            })
            ->where('status', TelegramGroupAnalysisRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        $newAfterCompleted = 0;

        if ($completed?->completed_at !== null) {
            $newAfterCompleted = Message::query()
                ->where('telegram_group_id', $group->id)
                ->when($range !== null, function ($query) use ($range): void {
                    $query->where('occurred_at', '>=', $range['from'])
                        ->where('occurred_at', '<', $range['to']);
                })
                ->where(function ($query) use ($completed): void {
                    $query->where('occurred_at', '>', $completed->completed_at)
                        ->orWhere('created_at', '>', $completed->completed_at);
                })
                ->count();
        }

        $staleThreshold = max(1, (int) config('group_search.stale_after_new_messages'));
        $stale = $completed !== null && $newAfterCompleted >= $staleThreshold;

        $status = match (true) {
            $queued => 'queued',
            $stale => 'partial',
            $completed !== null && ! $stale => 'available',
            $knowledgeCount > 0 => 'partial',
            default => 'missing',
        };

        return [
            'raw_messages' => $rawCount,
            'analysis_runs' => $completed !== null ? 1 : 0,
            'knowledge_items' => $knowledgeCount,
            'latest_completed_at' => $completed?->completed_at?->toIso8601String(),
            'status' => $status,
            'queued' => $queued,
            'stale' => $stale,
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     */
    public function rawCount(TelegramGroup $group, ?array $range): int
    {
        return Message::query()
            ->where('telegram_group_id', $group->id)
            ->when($range !== null, function ($query) use ($range): void {
                $query->where('occurred_at', '>=', $range['from'])
                    ->where('occurred_at', '<', $range['to']);
            })
            ->count();
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     */
    public function knowledgeCount(TelegramGroup $group, ?array $range): int
    {
        return TelegramGroupKnowledge::query()
            ->where('telegram_group_id', $group->id)
            ->where('status', TelegramGroupKnowledgeStatus::Active)
            ->when($range !== null, function ($query) use ($range): void {
                $query->where(function ($builder) use ($range): void {
                    $builder->where(function ($inner) use ($range): void {
                        $inner->whereNotNull('valid_from')
                            ->whereNotNull('valid_until')
                            ->where('valid_from', '<', $range['to'])
                            ->where('valid_until', '>', $range['from']);
                    })->orWhere(function ($inner) use ($range): void {
                        $inner->whereNull('valid_from')
                            ->whereNotNull('generated_at')
                            ->where('generated_at', '>=', $range['from'])
                            ->where('generated_at', '<', $range['to']);
                    });
                });
            })
            ->count();
    }
}
