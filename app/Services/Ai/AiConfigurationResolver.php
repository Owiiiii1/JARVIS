<?php

namespace App\Services\Ai;

use App\Enums\AiRoleKey;
use App\Enums\UserRole;
use App\Models\AiRoleSetting;
use App\Models\User;
use App\Services\Ai\Exceptions\AiConfigurationException;

final class AiConfigurationResolver
{
    public function resolveConversation(User $user): AiRoleSetting
    {
        $role = $user->role === UserRole::Owner
            ? AiRoleKey::OwnerConversation
            : AiRoleKey::UserConversation;

        return $this->require($role);
    }

    public function resolveAnalysis(): AiRoleSetting
    {
        return $this->require(AiRoleKey::OwnerAnalysis);
    }

    public function require(AiRoleKey $role): AiRoleSetting
    {
        $setting = AiRoleSetting::query()
            ->where('role_key', $role->value)
            ->first();

        if ($setting === null) {
            throw new AiConfigurationException('AI configuration is missing: '.$role->value);
        }

        return $setting;
    }
}
