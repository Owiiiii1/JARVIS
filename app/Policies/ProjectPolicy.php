<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\Users\UserCapability;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PROJECTS);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PROJECTS);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function attach(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    private function owns(User $user, Project $project): bool
    {
        return $user->isActive()
            && $user->canUseCapability(UserCapability::PROJECTS)
            && (int) $project->user_id === (int) $user->id;
    }
}
