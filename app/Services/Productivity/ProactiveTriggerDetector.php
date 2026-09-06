<?php

namespace App\Services\Productivity;

use App\Enums\ProactiveSuggestionType;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Synthesis\DTO\SynthesisResult;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;

final class ProactiveTriggerDetector
{
    public function __construct(
        private readonly ProactivePolicy $policy = new ProactivePolicy,
    ) {}

    /**
     * @return list<array{trigger: string, dedupe_key: string, title: string, body: string, source_id: int}>
     */
    public function forTask(Task $task, CarbonImmutable $now): array
    {
        if (! TaskLifecycle::isOpen($task) || $task->due_at === null) {
            return [];
        }

        $due = $task->due_at->utc();
        $events = [];

        if ($due->lessThan($now->utc())) {
            $events[] = [
                'trigger' => 'task_overdue',
                'dedupe_key' => 'proactive:task_overdue:'.$task->id,
                'title' => 'Просроченная задача',
                'body' => 'Задача всё ещё открыта и просрочена: '.$task->title,
                'source_id' => (int) $task->id,
            ];
        } elseif (
            in_array($task->priority, [TaskPriority::High, TaskPriority::Urgent], true)
            && $due->lessThanOrEqualTo($now->utc()->addHours($this->policy->approachHours()))
        ) {
            $events[] = [
                'trigger' => 'task_approaching',
                'dedupe_key' => 'proactive:task_approaching:'.$task->id.':'.$now->utc()->toDateString(),
                'title' => 'Скоро дедлайн',
                'body' => 'Скоро срок у важной задачи: '.$task->title,
                'source_id' => (int) $task->id,
            ];
        }

        return $events;
    }

    /**
     * @return list<array{trigger: string, dedupe_key: string, title: string, body: string, source_id: int, source_type: string}>
     */
    public function forSynthesis(SynthesisResult $result): array
    {
        $events = [];

        foreach (array_slice($result->attention, 0, 8) as $item) {
            if (! $item instanceof SynthesisItem) {
                continue;
            }

            $type = $this->suggestionType($item);

            if ($type === null) {
                continue;
            }

            $source = $item->sources[0] ?? null;
            $sourceId = $source?->taskId ?? $source?->watcherId ?? $source?->projectId ?? $source?->knowledgeEventId ?? $source?->entityId ?? 0;
            $sourceType = $source?->domain ?? 'synthesis';

            $events[] = [
                'trigger' => $type->value,
                'dedupe_key' => 'proactive:'.$type->value.':'.($item->fingerprint !== '' ? $item->fingerprint : sha1($item->title)),
                'title' => $this->titleFor($type),
                'body' => ($item->why ?? $item->title).($item->recommendedNextStep ? ' '.$item->recommendedNextStep : ''),
                'source_id' => (int) $sourceId,
                'source_type' => $sourceType,
            ];
        }

        return $events;
    }

    private function suggestionType(SynthesisItem $item): ?ProactiveSuggestionType
    {
        $raw = (string) ($item->extra['suggestion_type'] ?? $item->extra['reason'] ?? '');

        return match ($raw) {
            'follow_up', 'waiting_too_long' => ProactiveSuggestionType::WaitingTooLong,
            'project_blocked', 'blocker' => ProactiveSuggestionType::ProjectBlocked,
            'deadline_risk', 'deadline_24h' => ProactiveSuggestionType::DeadlineRisk,
            'stale_project' => ProactiveSuggestionType::StaleProject,
            'commitment_due' => ProactiveSuggestionType::CommitmentDue,
            default => null,
        };
    }

    private function titleFor(ProactiveSuggestionType $type): string
    {
        return match ($type) {
            ProactiveSuggestionType::FollowUp, ProactiveSuggestionType::WaitingTooLong => 'Стоит написать',
            ProactiveSuggestionType::ProjectBlocked => 'Проект заблокирован',
            ProactiveSuggestionType::DeadlineRisk => 'Риск дедлайна',
            ProactiveSuggestionType::StaleProject => 'Проект без движения',
            ProactiveSuggestionType::CommitmentDue => 'Обещание скоро истекает',
        };
    }
}
