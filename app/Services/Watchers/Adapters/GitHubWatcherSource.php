<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\WatcherConditionType;
use App\Enums\WatcherTriggerType;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\GitHubWatcherClient;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class GitHubWatcherSource implements WatcherSourceAdapter
{
    public function __construct(
        private readonly GitHubWatcherClient $github,
    ) {}

    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::GithubEvent;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $source = is_array($watcher->source_config) ? $watcher->source_config : [];
        $limit = max(1, (int) config('watchers.limits.max_observations', 20));

        return match ($watcher->condition_type) {
            WatcherConditionType::GithubPrStateChanged => $this->pulls($user, $watcher, $source, $limit),
            WatcherConditionType::GithubWorkflowFailed => $this->workflows($user, $watcher, $source, $limit),
            default => $this->commits($user, $watcher, $source, $limit),
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<WatcherObservation>
     */
    private function commits(User $user, Watcher $watcher, array $source, int $limit): array
    {
        $observations = [];
        foreach (array_slice($this->github->commits($user, $source), 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sha = (string) ($row['sha'] ?? '');
            if ($sha === '') {
                continue;
            }
            $observations[] = new WatcherObservation(
                sourceType: 'github',
                sourceId: $sha,
                eventType: 'github_commit_seen',
                fingerprint: WatcherSupport::fingerprint('github', (string) $watcher->id, 'commit', $sha),
                occurredAt: isset($row['timestamp']) && $row['timestamp'] !== ''
                    ? CarbonImmutable::parse((string) $row['timestamp'])->utc()
                    : CarbonImmutable::now('UTC'),
                title: WatcherSupport::summary((string) ($row['message'] ?? $sha)),
                metadata: WatcherSupport::boundMetadata([
                    'sha' => $sha,
                    'author' => (string) ($row['author'] ?? ''),
                    'repository' => (string) ($source['repository'] ?? ''),
                ]),
                projectId: $watcher->project_id,
                entityId: $watcher->knowledge_entity_id,
            );
        }

        return $observations;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<WatcherObservation>
     */
    private function pulls(User $user, Watcher $watcher, array $source, int $limit): array
    {
        $observations = [];
        foreach (array_slice($this->github->pullRequests($user, $source), 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $number = (string) ((int) ($row['number'] ?? 0));
            $state = (string) ($row['state'] ?? '');
            if ($number === '0') {
                continue;
            }
            $observations[] = new WatcherObservation(
                sourceType: 'github',
                sourceId: $number,
                eventType: 'github_pr_state_changed',
                fingerprint: WatcherSupport::fingerprint('github', (string) $watcher->id, 'pr', $number, $state),
                occurredAt: CarbonImmutable::now('UTC'),
                title: WatcherSupport::summary((string) ($row['title'] ?? 'PR #'.$number)),
                metadata: WatcherSupport::boundMetadata([
                    'status' => $state,
                    'number' => $number,
                    'repository' => (string) ($source['repository'] ?? ''),
                ]),
                projectId: $watcher->project_id,
            );
        }

        return $observations;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<WatcherObservation>
     */
    private function workflows(User $user, Watcher $watcher, array $source, int $limit): array
    {
        $observations = [];
        foreach (array_slice($this->github->workflowRuns($user, $source), 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $conclusion = (string) ($row['conclusion'] ?? '');
            if ($conclusion !== 'failure') {
                continue;
            }
            $id = (string) ((int) ($row['id'] ?? 0));
            if ($id === '0') {
                continue;
            }
            $observations[] = new WatcherObservation(
                sourceType: 'github',
                sourceId: $id,
                eventType: 'github_workflow_failed',
                fingerprint: WatcherSupport::fingerprint('github', (string) $watcher->id, 'workflow', $id, $conclusion),
                occurredAt: CarbonImmutable::now('UTC'),
                title: WatcherSupport::summary((string) ($row['name'] ?? 'Workflow failed')),
                metadata: WatcherSupport::boundMetadata([
                    'status' => $conclusion,
                    'repository' => (string) ($source['repository'] ?? ''),
                ]),
                projectId: $watcher->project_id,
            );
        }

        return $observations;
    }
}
