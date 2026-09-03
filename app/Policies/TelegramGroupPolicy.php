<?php

namespace App\Policies;

use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Users\UserCapability;

class TelegramGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::TELEGRAM_GROUPS);
    }

    public function view(User $user, TelegramGroup $group): bool
    {
        return $this->manage($user);
    }

    public function send(User $user, TelegramGroup $group): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, TelegramGroup $group): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::TELEGRAM_GROUPS);
    }
}
