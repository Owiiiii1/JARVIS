<?php

namespace App\Services\Watchers\Contracts;

use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\DTO\WatcherObservation;

interface WatcherSourceAdapter
{
    public function supports(Watcher $watcher): bool;

    /**
     * @return list<WatcherObservation>
     */
    public function check(User $user, Watcher $watcher): array;
}
