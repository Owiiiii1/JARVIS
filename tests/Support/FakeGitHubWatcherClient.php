<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Watchers\Contracts\GitHubWatcherClient;

final class FakeGitHubWatcherClient implements GitHubWatcherClient
{
    /** @var list<array<string, mixed>> */
    public array $commits = [];

    /** @var list<array<string, mixed>> */
    public array $pullRequests = [];

    /** @var list<array<string, mixed>> */
    public array $workflowRuns = [];

    public int $commitCalls = 0;

    public ?\Throwable $exception = null;

    public function commits(User $user, array $source): array
    {
        $this->commitCalls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->commits;
    }

    public function pullRequests(User $user, array $source): array
    {
        return $this->pullRequests;
    }

    public function workflowRuns(User $user, array $source): array
    {
        return $this->workflowRuns;
    }
}
