<?php

namespace App\Services\Projects;

use App\Enums\MemoryScope;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Project;
use App\Models\Topic;
use App\Models\User;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Users\UserCapability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectService
{
    /**
     * @return Collection<int, Project>
     */
    public function listForOwner(User $user, bool $includeArchived = true): Collection
    {
        $this->assertCanManage($user);

        $query = Project::query()
            ->where('user_id', $user->id)
            ->withCount(['conversations', 'topics', 'memories'])
            ->orderBy('name');

        if (! $includeArchived) {
            $query->where('status', ProjectStatus::Active);
        }

        return $query->get();
    }

    public function create(User $user, string $name, ?string $description = null): Project
    {
        $this->assertCanManage($user);

        $normalized = $this->normalizedName($name);
        $this->assertUnique($user, $normalized);

        return Project::query()->create([
            'user_id' => $user->id,
            'name' => mb_substr(trim($name), 0, 120),
            'normalized_name' => $normalized,
            'description' => $this->normalizeDescription($description),
            'status' => ProjectStatus::Active,
        ]);
    }

    public function update(User $user, Project $project, string $name, ?string $description = null): Project
    {
        $this->assertOwns($user, $project);

        $normalized = $this->normalizedName($name);
        $this->assertUnique($user, $normalized, $project->id);

        $project->forceFill([
            'name' => mb_substr(trim($name), 0, 120),
            'normalized_name' => $normalized,
            'description' => $this->normalizeDescription($description),
        ])->save();

        return $project->refresh();
    }

    public function archive(User $user, Project $project): Project
    {
        $this->assertOwns($user, $project);

        $project->forceFill(['status' => ProjectStatus::Archived])->save();

        return $project->refresh();
    }

    public function restore(User $user, Project $project): Project
    {
        $this->assertOwns($user, $project);

        $project->forceFill(['status' => ProjectStatus::Active])->save();

        return $project->refresh();
    }

    public function findOwned(User $user, int $projectId): ?Project
    {
        $this->assertCanManage($user);

        return Project::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->first();
    }

    public function attachConversation(User $user, Project $project, Conversation $conversation): void
    {
        $this->assertOwns($user, $project);

        if ((int) $conversation->user_id !== (int) $project->user_id) {
            throw new ProjectException('foreign_conversation');
        }

        $this->attachPivot($project, 'conversations', $conversation->id);
    }

    public function detachConversation(User $user, Project $project, Conversation $conversation): void
    {
        $this->assertOwns($user, $project);
        $project->conversations()->detach($conversation->id);
    }

    public function attachTopic(User $user, Project $project, Topic $topic): void
    {
        $this->assertOwns($user, $project);

        if ((int) $topic->user_id !== (int) $project->user_id) {
            throw new ProjectException('foreign_topic');
        }

        $this->attachPivot($project, 'topics', $topic->id);
    }

    public function detachTopic(User $user, Project $project, Topic $topic): void
    {
        $this->assertOwns($user, $project);
        $project->topics()->detach($topic->id);
    }

    public function attachMemory(User $user, Project $project, Memory $memory): void
    {
        $this->assertOwns($user, $project);

        if ((int) $memory->user_id !== (int) $project->user_id || $memory->scope !== MemoryScope::Personal) {
            throw new ProjectException('foreign_memory');
        }

        $this->attachPivot($project, 'memories', $memory->id);
    }

    public function detachMemory(User $user, Project $project, Memory $memory): void
    {
        $this->assertOwns($user, $project);
        $project->memories()->detach($memory->id);
    }

    public function assertCanManage(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::PROJECTS)) {
            throw new ProjectException('forbidden');
        }
    }

    public function assertOwns(User $user, Project $project): void
    {
        $this->assertCanManage($user);

        if ((int) $project->user_id !== (int) $user->id) {
            throw new ProjectException('forbidden');
        }
    }

    private function attachPivot(Project $project, string $relation, int $id): void
    {
        $exists = $project->{$relation}()->whereKey($id)->exists();

        if ($exists) {
            return;
        }

        DB::transaction(function () use ($project, $relation, $id): void {
            $project->{$relation}()->attach($id, [
                'attached_at' => now(),
            ]);
        });
    }

    private function normalizedName(string $name): string
    {
        $normalized = ProjectNameNormalizer::normalize($name);

        if ($normalized === '') {
            throw new ProjectException('invalid_name');
        }

        return $normalized;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);
        $max = (int) config('projects.description_max');

        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, $max);
    }

    private function assertUnique(User $user, string $normalized, ?int $ignoreId = null): void
    {
        $query = Project::query()
            ->where('user_id', $user->id)
            ->where('normalized_name', $normalized);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw new ProjectException('duplicate_name');
        }
    }
}
