<?php

namespace App\Services\Watchers\Contracts;

use App\Models\User;

interface GitHubWatcherClient
{
    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public function commits(User $user, array $source): array;

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public function pullRequests(User $user, array $source): array;

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public function workflowRuns(User $user, array $source): array;
}
