<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationType;
use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeEntity;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\User;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use Throwable;

final class KnowledgeDeterministicIngestor
{
    public function __construct(
        private readonly KnowledgeIngestionService $ingestion = new KnowledgeIngestionService,
    ) {}

    public function taskCreated(Task $task): void
    {
        $this->safe(function () use ($task): void {
            $user = $task->user ?? User::query()->find($task->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::Task,
                fingerprint: KnowledgeSourceRef::hash('task_created', (string) $task->id),
                confidence: KnowledgeConfidence::deterministic(),
                conversationId: $task->source_conversation_id,
                messageId: $task->source_message_id,
                projectId: $task->project_id,
                taskId: $task->id,
            );
            $entities = [$this->taskEntity($user, $task, $source)];
            $projectEntity = $this->projectEntityFor($user, $task->project_id, $source);

            if ($projectEntity !== null) {
                $entities[] = $projectEntity;
                $this->ingestion->upsertRelationship($user, $entities[0], $projectEntity, KnowledgeRelationType::RelatedTo, $source);
            }

            $this->ingestion->recordEvent($user, KnowledgeEventType::TaskCreated, $task->title, $source, $entities);
        });
    }

    public function taskCompleted(Task $task): void
    {
        $this->safe(function () use ($task): void {
            $user = $task->user ?? User::query()->find($task->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::Task,
                fingerprint: KnowledgeSourceRef::hash('task_completed', (string) $task->id),
                confidence: KnowledgeConfidence::deterministic(),
                conversationId: $task->source_conversation_id,
                projectId: $task->project_id,
                taskId: $task->id,
            );
            $entities = [$this->taskEntity($user, $task, $source)];
            $projectEntity = $this->projectEntityFor($user, $task->project_id, $source);

            if ($projectEntity !== null) {
                $entities[] = $projectEntity;
            }

            $this->ingestion->recordEvent($user, KnowledgeEventType::TaskCompleted, $task->title, $source, $entities);
        });
    }

    public function reminderCreated(Reminder $reminder): void
    {
        $this->safe(function () use ($reminder): void {
            $user = $reminder->user ?? User::query()->find($reminder->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::Reminder,
                fingerprint: KnowledgeSourceRef::hash('reminder_created', (string) $reminder->id),
                confidence: KnowledgeConfidence::deterministic(),
                conversationId: $reminder->source_conversation_id,
                messageId: $reminder->source_message_id,
                reminderId: $reminder->id,
                taskId: $reminder->task_id,
            );
            $topic = $this->ingestion->upsertEntity($user, KnowledgeEntityType::Topic, mb_substr($reminder->text, 0, 80), $source, [
                'summary' => $reminder->text,
            ]);
            $this->ingestion->recordEvent($user, KnowledgeEventType::ReminderCreated, mb_substr($reminder->text, 0, 240), $source, [$topic]);
        });
    }

    public function projectCreated(Project $project): void
    {
        $this->safe(function () use ($project): void {
            $user = $project->user ?? User::query()->find($project->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::Project,
                fingerprint: KnowledgeSourceRef::hash('project_created', (string) $project->id),
                confidence: KnowledgeConfidence::deterministic(),
                projectId: $project->id,
            );
            $entity = $this->ingestion->upsertEntity($user, KnowledgeEntityType::Project, $project->name, $source, [
                'summary' => $project->description,
                'project_id' => $project->id,
            ]);
            $this->ingestion->recordEvent($user, KnowledgeEventType::ProjectCreated, $project->name, $source, [$entity]);
        });
    }

    public function projectArchived(Project $project): void
    {
        $this->safe(function () use ($project): void {
            $user = $project->user ?? User::query()->find($project->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::Project,
                fingerprint: KnowledgeSourceRef::hash('project_archived', (string) $project->id),
                confidence: KnowledgeConfidence::deterministic(),
                projectId: $project->id,
            );
            $entity = $this->ingestion->upsertEntity($user, KnowledgeEntityType::Project, $project->name, $source, [
                'project_id' => $project->id,
            ]);
            $this->ingestion->recordEvent($user, KnowledgeEventType::ProjectArchived, $project->name, $source, [$entity]);
        });
    }

    public function fileReady(StoredFile $file): void
    {
        $this->safe(function () use ($file): void {
            $user = $file->user ?? User::query()->find($file->user_id);

            if ($user === null) {
                return;
            }

            $source = new KnowledgeSourceRef(
                type: KnowledgeSourceType::StoredFile,
                fingerprint: KnowledgeSourceRef::hash('file_uploaded', (string) $file->id),
                confidence: KnowledgeConfidence::deterministic(),
                storedFileId: $file->id,
            );
            $entity = $this->ingestion->upsertEntity($user, KnowledgeEntityType::File, (string) ($file->display_name ?: $file->original_name ?: 'File #'.$file->id), $source, [
                'summary' => $file->summary,
                'metadata' => ['stored_file_id' => $file->id],
            ]);
            $this->ingestion->recordEvent($user, KnowledgeEventType::FileUploaded, $entity->name, $source, [$entity]);
        });
    }

    private function taskEntity(User $user, Task $task, KnowledgeSourceRef $source): KnowledgeEntity
    {
        return $this->ingestion->upsertEntity($user, KnowledgeEntityType::Custom, $task->title, $source, [
            'summary' => $task->description,
            'project_id' => $task->project_id,
            'metadata' => ['task_id' => $task->id],
        ]);
    }

    private function projectEntityFor(User $user, ?int $projectId, KnowledgeSourceRef $source): ?KnowledgeEntity
    {
        if ($projectId === null) {
            return null;
        }

        $project = Project::query()->where('user_id', $user->id)->whereKey($projectId)->first();

        if ($project === null) {
            return null;
        }

        return $this->ingestion->upsertEntity($user, KnowledgeEntityType::Project, $project->name, $source, [
            'project_id' => $project->id,
        ]);
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
        }
    }
}
