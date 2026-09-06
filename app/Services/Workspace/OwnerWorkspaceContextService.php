<?php

namespace App\Services\Workspace;

use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectService;
use App\Services\Users\UserCapability;

final class OwnerWorkspaceContextService
{
    public const PROJECT_LIMIT = 12;

    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    /**
     * Compact owner chrome for the main Workspace. Memory and Integrations live in Settings.
     *
     * @return array<string, mixed>
     */
    public function compact(User $user, Conversation $conversation): array
    {
        return [
            'projects' => $this->projectsSummary($user, $conversation),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectsSummary(User $user, Conversation $conversation): array
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        try {
            $attachedIds = $conversation->projects()
                ->pluck('projects.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            return $this->projects->listForOwner($user, includeArchived: false)
                ->take(self::PROJECT_LIMIT)
                ->map(static function ($project) use ($attachedIds): array {
                    return [
                        'id' => $project->id,
                        'name' => $project->name,
                        'status' => $project->status instanceof ProjectStatus
                            ? $project->status->value
                            : (string) $project->status,
                        'attached' => in_array((int) $project->id, $attachedIds, true),
                    ];
                })
                ->values()
                ->all();
        } catch (ProjectException) {
            return [];
        }
    }
}
