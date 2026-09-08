<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\ToolExecutionLogStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolExecutionLog;
use App\Models\User;
use Carbon\CarbonInterface;

final class RecentToolReferenceReader
{
    public const MAX_LOGS = 12;

    /**
     * @return list<ConversationalEntity>
     */
    public function forConversation(User $user, Conversation $conversation, ?CarbonInterface $now = null): array
    {
        $maxTurns = max(1, (int) config('context_budget.working_memory_turns', 4));
        $cutoff = ($now ?? now())->subHours(6);

        $logs = ToolExecutionLog::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', ToolExecutionLogStatus::Succeeded)
            ->where('finished_at', '>=', $cutoff)
            ->orderByDesc('id')
            ->limit(self::MAX_LOGS)
            ->get();

        $userTurnsAfter = $this->userTurnTimes($conversation);
        $entities = [];
        $seen = [];

        foreach ($logs as $log) {
            $expired = $this->expired($log, $userTurnsAfter, $maxTurns);

            foreach ($this->entitiesFromLog($log, $expired) as $entity) {
                $key = $entity->type.':'.(string) ($entity->id ?? $entity->label);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $entities[] = $entity;
            }
        }

        return array_slice($entities, 0, 8);
    }

    /**
     * @param  list<CarbonInterface>  $userTurnTimes
     */
    private function expired(ToolExecutionLog $log, array $userTurnTimes, int $maxTurns): bool
    {
        $finished = $log->finished_at;

        if ($finished === null) {
            return true;
        }

        $later = 0;

        foreach ($userTurnTimes as $occurredAt) {
            if ($occurredAt->greaterThan($finished)) {
                $later++;
            }
        }

        return $later > $maxTurns;
    }

    /**
     * @return list<CarbonInterface>
     */
    private function userTurnTimes(Conversation $conversation): array
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('occurred_at')
            ->filter()
            ->all();
    }

    /**
     * @return list<ConversationalEntity>
     */
    private function entitiesFromLog(ToolExecutionLog $log, bool $expired): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $entities = [];

        $map = [
            'task_id' => 'task',
            'reminder_id' => 'reminder',
            'project_id' => 'project',
            'file_id' => 'file',
            'calendar_event_id' => 'calendar_event',
            'watcher_id' => 'watcher',
        ];

        foreach ($map as $key => $type) {
            if (! isset($metadata[$key]) || ! is_numeric($metadata[$key])) {
                continue;
            }

            $id = (int) $metadata[$key];

            if ($id <= 0 && $type !== 'file') {
                continue;
            }

            $label = $this->labelFromMetadata($metadata, $type);
            $entities[] = new ConversationalEntity(
                type: $type,
                label: $label,
                id: $id > 0 ? $id : null,
                trusted: true,
                expired: $expired,
            );
        }

        if (isset($metadata['listed_tasks']) && is_array($metadata['listed_tasks'])) {
            foreach (array_slice($metadata['listed_tasks'], 0, 5) as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $id = (int) $row['id'];
                $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : 'Task';
                $entities[] = new ConversationalEntity(
                    type: 'task',
                    label: $title !== '' ? $title : 'Task',
                    id: $id,
                    trusted: true,
                    expired: $expired,
                );
            }
        }

        return $entities;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function labelFromMetadata(array $metadata, string $type): string
    {
        foreach (['title', 'text', 'name'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && trim($metadata[$key]) !== '') {
                return mb_substr(trim($metadata[$key]), 0, 80);
            }
        }

        return $type;
    }
}
