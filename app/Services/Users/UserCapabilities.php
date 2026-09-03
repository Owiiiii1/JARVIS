<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;

final class UserCapabilities
{
    /**
     * @return list<string>
     */
    public static function defaultsForRole(UserRole $role): array
    {
        if ($role === UserRole::Owner) {
            return ['*'];
        }

        return UserCapability::forRegularUser();
    }

    public static function userCan(User $user, string $capability): bool
    {
        if ($user->role === UserRole::Owner) {
            return true;
        }

        return in_array($capability, UserCapability::forRegularUser(), true);
    }
}
