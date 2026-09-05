<?php

namespace App\Services\Users;

use App\Enums\TelegramResponseMode;
use App\Models\User;

interface ResolvesTelegramResponseMode
{
    public function telegramResponseMode(User $user): TelegramResponseMode;
}
