<?php

namespace App\Services\Synthesis;

use App\Models\KnowledgeEntity;
use App\Models\KnowledgeRelationship;
use App\Models\Task;
use App\Models\Watcher;
use App\Services\Knowledge\KnowledgeNameNormalizer;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Workspace\Presentation\HumanSynthesisText;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge is evidence; the task table is the truth.
 *
 * A `depends_on` row recorded months ago stays valid history, but once the task behind it is
 * closed the dependency is resolved and no derived slice may present it as live work. This
 * resolver maps knowledge entities back to their canonical task so every slice can ask the
 * authoritative domain instead of trusting the graph.
 */
final class CanonicalStateResolver
{
    /** @var array<string, array<int, Task>> */
    private array $cache = [];

    /**
     * @return array<int, Task> knowledge entity id => canonical task
     */
    public function tasksByEntity(FactPack $pack): array
    {
        $key = spl_object_hash($pack);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($pack);
    }

    /**
     * @return array<int, true> knowledge entity ids whose canonical task is no longer open
     */
    public function closedTaskEntityIds(FactPack $pack): array
    {
        $closed = [];

        foreach ($this->tasksByEntity($pack) as $entityId => $task) {
            if (! TaskLifecycle::isOpen($task)) {
                $closed[$entityId] = true;
            }
        }

        return $closed;
    }

    /**
     * The canonical title always wins over whatever name the graph stored for the same thing.
     */
    public function entityLabel(FactPack $pack, ?KnowledgeEntity $entity, ?int $entityId = null): ?string
    {
        $id = (int) ($entity?->id ?? $entityId ?? 0);
        $task = $id > 0 ? ($this->tasksByEntity($pack)[$id] ?? null) : null;

        if ($task instanceof Task) {
            return (string) $task->title;
        }

        $name = HumanSynthesisText::entityName($entity?->name);

        return $name !== '' ? $name : null;
    }

    /**
     * Watchers may carry their task in the column or in the source config, and both must count.
     */
    public function watcherTaskId(Watcher $watcher): ?int
    {
        $id = (int) ($watcher->task_id ?? 0);

        if ($id > 0) {
            return $id;
        }

        $config = is_array($watcher->source_config) ? $watcher->source_config : [];
        $configured = (int) ($config['task_id'] ?? 0);

        return $configured > 0 ? $configured : null;
    }

    /**
     * Entities written by extraction rarely carry a task id, but they are usually named after the
     * task — sometimes with the id spelled into the name («Задача #253: Проверить билд»). Both
     * spellings are matched against the user's own tasks, and a name match still has to agree
     * with the canonical title before it is trusted.
     *
     * @param  array<int, KnowledgeEntity>  $entities
     * @param  list<int>  $missing
     * @return array<int, int>
     */
    private function taskIdsFromNames(array $entities, array $missing, FactPack $pack): array
    {
        $byTitle = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $byTitle[KnowledgeNameNormalizer::name((string) $task->title)] = (int) $task->id;
            }
        }

        $resolved = [];
        $numbered = [];

        foreach ($missing as $entityId) {
            $entity = $entities[$entityId] ?? null;

            if (! $entity instanceof KnowledgeEntity) {
                continue;
            }

            $name = (string) $entity->name;
            $normalized = KnowledgeNameNormalizer::name($name);

            if (isset($byTitle[$normalized])) {
                $resolved[$entityId] = $byTitle[$normalized];

                continue;
            }

            foreach ($byTitle as $title => $taskId) {
                if ($title !== '' && str_contains($normalized, $title)) {
                    $resolved[$entityId] = $taskId;

                    continue 2;
                }
            }

            if (preg_match('/#(\d+)/', $name, $matches) === 1) {
                $numbered[$entityId] = (int) $matches[1];
            }
        }

        if ($numbered !== [] && $pack->userId !== null) {
            $candidates = Task::query()
                ->where('user_id', $pack->userId)
                ->whereIn('id', array_values(array_unique($numbered)))
                ->limit(40)
                ->get()
                ->keyBy(static fn (Task $task): int => (int) $task->id);

            foreach ($numbered as $entityId => $taskId) {
                $task = $candidates->get($taskId);
                $entity = $entities[$entityId] ?? null;

                if (! $task instanceof Task || ! $entity instanceof KnowledgeEntity) {
                    continue;
                }

                $title = KnowledgeNameNormalizer::name((string) $task->title);

                if ($title !== '' && str_contains(KnowledgeNameNormalizer::name((string) $entity->name), $title)) {
                    $resolved[$entityId] = $taskId;
                }
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, Task>
     */
    private function resolve(FactPack $pack): array
    {
        $entities = [];

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship) {
                continue;
            }

            foreach ([$relation->sourceEntity, $relation->targetEntity] as $entity) {
                if ($entity instanceof KnowledgeEntity) {
                    $entities[(int) $entity->id] = $entity;
                }
            }
        }

        foreach ([...$pack->entities, ...$pack->people] as $entity) {
            if ($entity instanceof KnowledgeEntity) {
                $entities[(int) $entity->id] = $entity;
            }
        }

        if ($entities === []) {
            return [];
        }

        $taskIdByEntity = [];

        foreach ($entities as $entityId => $entity) {
            $metadata = is_array($entity->metadata) ? $entity->metadata : [];
            $taskId = (int) ($metadata['task_id'] ?? 0);

            if ($taskId > 0) {
                $taskIdByEntity[$entityId] = $taskId;
            }
        }

        $missing = array_values(array_diff(array_keys($entities), array_keys($taskIdByEntity)));

        if ($missing !== []) {
            foreach ($this->taskIdsFromNames($entities, $missing, $pack) as $entityId => $taskId) {
                $taskIdByEntity[$entityId] = $taskId;
            }

            $missing = array_values(array_diff(array_keys($entities), array_keys($taskIdByEntity)));
        }

        if ($missing !== []) {
            $rows = DB::table('knowledge_entity_sources')
                ->whereIn('knowledge_entity_id', $missing)
                ->whereNotNull('task_id')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['knowledge_entity_id', 'task_id']);

            foreach ($rows as $row) {
                $entityId = (int) $row->knowledge_entity_id;

                if (! isset($taskIdByEntity[$entityId])) {
                    $taskIdByEntity[$entityId] = (int) $row->task_id;
                }
            }
        }

        if ($taskIdByEntity === []) {
            return [];
        }

        $tasks = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $tasks[(int) $task->id] = $task;
            }
        }

        $unloaded = array_values(array_diff(array_unique(array_values($taskIdByEntity)), array_keys($tasks)));

        if ($unloaded !== [] && $pack->userId !== null) {
            $fetched = Task::query()
                ->where('user_id', $pack->userId)
                ->whereIn('id', $unloaded)
                ->limit(60)
                ->get();

            foreach ($fetched as $task) {
                $tasks[(int) $task->id] = $task;
            }
        }

        $resolved = [];

        foreach ($taskIdByEntity as $entityId => $taskId) {
            $task = $tasks[$taskId] ?? null;

            if ($task instanceof Task && ($pack->userId === null || (int) $task->user_id === $pack->userId)) {
                $resolved[$entityId] = $task;
            }
        }

        return $resolved;
    }
}
