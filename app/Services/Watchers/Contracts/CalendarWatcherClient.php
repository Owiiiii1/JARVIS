<?php

namespace App\Services\Watchers\Contracts;

use App\Models\User;

interface CalendarWatcherClient
{
    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public function events(User $user, array $source): array;
}
