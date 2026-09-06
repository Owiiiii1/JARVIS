<?php

namespace App\Services\Watchers\Clients;

use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\GitHub\GitHubApiService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Contracts\GitHubWatcherClient;

final class LiveGitHubWatcherClient implements GitHubWatcherClient
{
    public function __construct(
        private readonly GitHubApiService $github,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function commits(User $user, array $source): array
    {
        $result = $this->github->listCommits($this->account($user), $this->repository($source), [
            'sha' => $source['branch'] ?? ($source['ref'] ?? null),
            'max_results' => 20,
        ]);

        if (isset($result['ambiguous'])) {
            throw new IntegrationException('ambiguous_repository', 'GitHub repository is ambiguous.');
        }

        return is_array($result['commits'] ?? null) ? $result['commits'] : [];
    }

    public function pullRequests(User $user, array $source): array
    {
        $result = $this->github->listPullRequests($this->account($user), $this->repository($source), [
            'state' => $source['state'] ?? 'all',
            'max_results' => 20,
        ]);

        if (isset($result['ambiguous'])) {
            throw new IntegrationException('ambiguous_repository', 'GitHub repository is ambiguous.');
        }

        return is_array($result['pull_requests'] ?? null) ? $result['pull_requests'] : [];
    }

    public function workflowRuns(User $user, array $source): array
    {
        $result = $this->github->listWorkflowRuns($this->account($user), $this->repository($source), [
            'branch' => $source['branch'] ?? null,
            'max_results' => 20,
        ]);

        if (isset($result['ambiguous'])) {
            throw new IntegrationException('ambiguous_repository', 'GitHub repository is ambiguous.');
        }

        return is_array($result['workflow_runs'] ?? null) ? $result['workflow_runs'] : [];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function repository(array $source): string
    {
        $repository = trim((string) ($source['repository'] ?? ''));
        if ($repository === '') {
            throw new IntegrationException('invalid_arguments', 'GitHub repository is required.');
        }

        return $repository;
    }

    private function account(User $user): IntegrationAccount
    {
        $account = $this->accounts->getActiveAccount($user, 'github');
        if ($account === null || ! $user->canUseCapability(UserCapability::GITHUB)) {
            throw new IntegrationException('github_not_connected', 'GitHub is not connected.');
        }

        return $account;
    }
}
