<?php

namespace App\Services\Synthesis;

use App\Enums\CommitmentStatus;
use App\Enums\KnowledgeEventType;
use App\Models\KnowledgeEvent;
use App\Models\Task;
use App\Models\User;

final class CommitmentLifecycle
{
    public function fulfillLinked(User $user, Task $task): int
    {
        $updated = 0;
        $events = KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->where('type', KnowledgeEventType::CommitmentMade)
            ->get();

        foreach ($events as $event) {
            $metadata = $event->metadata ?? [];
            $linked = isset($metadata['task_id']) ? (int) $metadata['task_id'] : 0;

            if ($linked !== (int) $task->id) {
                continue;
            }

            $status = CommitmentStatus::tryFromLoose($metadata['status'] ?? 'open') ?? CommitmentStatus::Open;

            if ($status !== CommitmentStatus::Open) {
                continue;
            }

            $metadata['status'] = CommitmentStatus::Fulfilled->value;
            $event->forceFill(['metadata' => $metadata])->save();
            $updated++;
        }

        return $updated;
    }
}
