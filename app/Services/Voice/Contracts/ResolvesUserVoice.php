<?php

namespace App\Services\Voice\Contracts;

use App\Models\User;

interface ResolvesUserVoice
{
    public function voiceIdFor(User $user): string;
}
