<?php

namespace App\Services\Integrations\GitHub;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GitHubApiService
{
    public function __construct(
        private readonly GitHubCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listRepositories(IntegrationAccount $account, array $options = []): array
    {
        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_repositories', 30));
        $query = [
            'per_page' => min(100, $limit),
            'sort' => 'updated',
            'direction' => 'desc',
        ];
        $visibility = $this->optionalString($options['visibility'] ?? null);
        if (in_array($visibility, ['all', 'public', 'private'], true)) {
            $query['visibility'] = $visibility;
        }
        $affiliation = $this->optionalString($options['affiliation'] ?? null);
        if ($affiliation !== null) {
            $query['affiliation'] = $affiliation;
        }

        $needle = $this->normalizeName($this->optionalString($options['query'] ?? null) ?? '');
        $items = [];
        $truncated = false;
        $page = 1;

        while (count($items) < $limit && $page <= 5) {
            $query['page'] = $page;
            $payload = $this->get($account, '/user/repos', $query);
            $pageItems = is_array($payload) ? $payload : [];
            if ($pageItems === []) {
                break;
            }

            foreach ($pageItems as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                $mapped = $this->mapRepository($raw);
                if ($needle !== '' && ! $this->matchesQuery($mapped, $needle)) {
                    continue;
                }
                $items[] = $mapped;
                if (count($items) >= $limit) {
                    $truncated = count($pageItems) >= (int) $query['per_page'] || count($items) >= $limit;
                    break 2;
                }
            }

            if (count($pageItems) < (int) $query['per_page']) {
                break;
            }
            $page++;
        }

        return [
            'repositories' => $items,
            'truncated' => $truncated,
            'result_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRepository(IntegrationAccount $account, string $repository): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']));

        return [
            'repository' => $this->mapRepositoryDetail($payload),
            'result_count' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listBranches(IntegrationAccount $account, string $repository, ?int $maxResults = null): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $limit = $this->bound($maxResults, (int) config('github.max_branches', 30));
        $items = [];
        $page = 1;
        $truncated = false;

        while (count($items) < $limit && $page <= 5) {
            $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/branches', [
                'per_page' => min(100, $limit),
                'page' => $page,
            ]);
            $pageItems = is_array($payload) ? $payload : [];
            if ($pageItems === []) {
                break;
            }
            foreach ($pageItems as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                $items[] = [
                    'name' => (string) ($raw['name'] ?? ''),
                    'sha' => (string) ($raw['commit']['sha'] ?? ''),
                    'protected' => (bool) ($raw['protected'] ?? false),
                ];
                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }
            }
            if (count($pageItems) < min(100, $limit)) {
                break;
            }
            $page++;
        }

        return [
            'repository' => $resolved['full_name'],
            'branches' => $items,
            'truncated' => $truncated,
            'result_count' => count($items),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listCommits(IntegrationAccount $account, string $repository, array $options = []): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_commits', 20));
        $query = [
            'per_page' => min(100, $limit),
        ];
        foreach (['sha', 'since', 'until', 'author', 'path'] as $key) {
            $value = $this->optionalString($options[$key] ?? ($key === 'sha' ? ($options['branch'] ?? $options['ref'] ?? null) : null));
            if ($value !== null) {
                $query[$key === 'sha' ? 'sha' : $key] = $value;
            }
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/commits', $query);
        $items = [];
        foreach (array_slice(is_array($payload) ? $payload : [], 0, $limit) as $raw) {
            if (is_array($raw)) {
                $items[] = $this->mapCommitSummary($raw);
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'commits' => $items,
            'truncated' => is_array($payload) && count($payload) > $limit,
            'result_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCommit(IntegrationAccount $account, string $repository, string $sha): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $sha = $this->assertRef($sha);
        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/commits/'.rawurlencode($sha));
        $maxFiles = (int) config('github.max_commit_files', 40);
        $files = [];
        $truncatedFiles = false;
        $rawFiles = is_array($payload['files'] ?? null) ? $payload['files'] : [];
        foreach ($rawFiles as $index => $file) {
            if (! is_array($file)) {
                continue;
            }
            if ($index >= $maxFiles) {
                $truncatedFiles = true;
                break;
            }
            $files[] = $this->mapFileChange($file);
        }

        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];

        return [
            'repository' => $resolved['full_name'],
            'commit' => $this->mapCommitSummary($payload),
            'stats' => [
                'additions' => (int) ($stats['additions'] ?? 0),
                'deletions' => (int) ($stats['deletions'] ?? 0),
                'total' => (int) ($stats['total'] ?? 0),
                'files_changed' => count($rawFiles),
            ],
            'files' => $files,
            'truncated' => $truncatedFiles,
            'result_count' => count($files),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function compareRefs(IntegrationAccount $account, string $repository, string $base, string $head): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $base = $this->assertRef($base);
        $head = $this->assertRef($head);
        $payload = $this->get(
            $account,
            $this->repoPath($resolved['full_name']).'/compare/'.rawurlencode($base).'...'.rawurlencode($head),
        );

        $maxCommits = (int) config('github.max_commits', 20);
        $maxFiles = (int) config('github.max_commit_files', 40);
        $commits = [];
        foreach (array_slice((array) ($payload['commits'] ?? []), 0, $maxCommits) as $raw) {
            if (is_array($raw)) {
                $commits[] = $this->mapCommitSummary($raw);
            }
        }
        $files = [];
        foreach (array_slice((array) ($payload['files'] ?? []), 0, $maxFiles) as $raw) {
            if (is_array($raw)) {
                $files[] = $this->mapFileChange($raw);
            }
        }

        $truncated = count((array) ($payload['commits'] ?? [])) > $maxCommits
            || count((array) ($payload['files'] ?? [])) > $maxFiles;

        return [
            'repository' => $resolved['full_name'],
            'base' => $base,
            'head' => $head,
            'status' => (string) ($payload['status'] ?? ''),
            'ahead_by' => (int) ($payload['ahead_by'] ?? 0),
            'behind_by' => (int) ($payload['behind_by'] ?? 0),
            'total_commits' => (int) ($payload['total_commits'] ?? count($commits)),
            'commits' => $commits,
            'files' => $files,
            'stats' => [
                'files_changed' => count((array) ($payload['files'] ?? [])),
            ],
            'truncated' => $truncated,
            'result_count' => count($files),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(IntegrationAccount $account, string $repository, string $path, ?string $ref = null): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $path = $this->assertPath($path);
        $query = [];
        if ($ref !== null && trim($ref) !== '') {
            $query['ref'] = $this->assertRef($ref);
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/contents/'.$this->encodePath($path), $query);
        if (($payload['type'] ?? '') !== 'file') {
            throw new IntegrationException('github_file_not_found', 'GitHub path is not a file.');
        }

        $size = (int) ($payload['size'] ?? 0);
        $decoded = $this->decodeContent($payload);
        $maxChars = (int) config('github.max_file_chars', 12000);
        $truncated = mb_strlen($decoded) > $maxChars;
        if ($truncated) {
            $decoded = mb_substr($decoded, 0, $maxChars);
        }

        return [
            'repository' => $resolved['full_name'],
            'path' => (string) ($payload['path'] ?? $path),
            'ref' => $query['ref'] ?? (string) ($payload['sha'] ?? ''),
            'sha' => (string) ($payload['sha'] ?? ''),
            'size' => $size,
            'content' => $decoded,
            'truncated' => $truncated,
            'html_url' => isset($payload['html_url']) ? (string) $payload['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function searchCode(IntegrationAccount $account, string $query, array $options = []): array
    {
        $q = trim($query);
        if ($q === '') {
            throw new IntegrationException('github_validation_failed', 'Code search query is required.');
        }

        $parts = [$q];
        $repository = $this->optionalString($options['repository'] ?? null);
        if ($repository !== null) {
            $resolved = $this->resolveRepository($account, $repository);
            if (isset($resolved['ambiguous'])) {
                return $resolved;
            }
            $parts[] = 'repo:'.$resolved['full_name'];
        }
        $path = $this->optionalString($options['path'] ?? null);
        if ($path !== null) {
            $parts[] = 'path:'.$path;
        }
        $language = $this->optionalString($options['language'] ?? null);
        if ($language !== null) {
            $parts[] = 'language:'.$language;
        }

        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_search_results', 15));
        $payload = $this->get($account, '/search/code', [
            'q' => implode(' ', $parts),
            'per_page' => min(100, $limit),
        ], accept: 'application/vnd.github.text-match+json');

        $items = [];
        foreach (array_slice((array) ($payload['items'] ?? []), 0, $limit) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $excerpt = null;
            $matches = is_array($raw['text_matches'] ?? null) ? $raw['text_matches'] : [];
            if (isset($matches[0]['fragment'])) {
                $excerpt = $this->truncate((string) $matches[0]['fragment'], 400);
            }
            $items[] = [
                'repository' => (string) ($raw['repository']['full_name'] ?? ''),
                'path' => (string) ($raw['path'] ?? ''),
                'sha' => (string) ($raw['sha'] ?? ''),
                'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
                'excerpt' => $excerpt,
            ];
        }

        $incomplete = (bool) ($payload['incomplete_results'] ?? false);

        return [
            'results' => $items,
            'truncated' => $incomplete || ((int) ($payload['total_count'] ?? 0) > count($items)),
            'incomplete_results' => $incomplete,
            'result_count' => count($items),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listIssues(IntegrationAccount $account, string $repository, array $options = []): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_issues', 20));
        $query = [
            'per_page' => min(100, $limit + 5),
            'state' => $this->issueState($options['state'] ?? 'open'),
        ];
        $labels = $this->stringList($options['labels'] ?? [], (int) config('github.max_labels', 10));
        if ($labels !== []) {
            $query['labels'] = implode(',', $labels);
        }
        $assignee = $this->optionalString($options['assignee'] ?? null);
        if ($assignee !== null) {
            $query['assignee'] = $assignee;
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/issues', $query);
        $needle = $this->normalizeName($this->optionalString($options['query'] ?? null) ?? '');
        $items = [];
        foreach (is_array($payload) ? $payload : [] as $raw) {
            if (! is_array($raw) || isset($raw['pull_request'])) {
                continue;
            }
            $mapped = $this->mapIssueSummary($raw);
            if ($needle !== '' && ! str_contains($this->normalizeName($mapped['title']), $needle)) {
                continue;
            }
            $items[] = $mapped;
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'issues' => $items,
            'truncated' => is_array($payload) && count($payload) > count($items),
            'result_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getIssue(IntegrationAccount $account, string $repository, int $number): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $number = $this->assertPositive($number, 'github_issue_not_found');
        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/issues/'.$number);
        $comments = [];
        $commentPayload = $this->get($account, $this->repoPath($resolved['full_name']).'/issues/'.$number.'/comments', [
            'per_page' => min(100, (int) config('github.max_issue_comments', 10)),
        ]);
        $maxComments = (int) config('github.max_issue_comments', 10);
        foreach (array_slice(is_array($commentPayload) ? $commentPayload : [], 0, $maxComments) as $raw) {
            if (is_array($raw)) {
                $comments[] = [
                    'id' => (int) ($raw['id'] ?? 0),
                    'author' => (string) ($raw['user']['login'] ?? ''),
                    'body' => $this->truncate((string) ($raw['body'] ?? ''), (int) config('github.max_comment_chars', 2000)),
                    'created_at' => (string) ($raw['created_at'] ?? ''),
                    'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
                ];
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'issue' => $this->mapIssueDetail($payload),
            'comments' => $comments,
            'truncated' => count(is_array($commentPayload) ? $commentPayload : []) > $maxComments
                || (bool) ($this->mapIssueDetail($payload)['body_truncated'] ?? false),
            'result_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listPullRequests(IntegrationAccount $account, string $repository, array $options = []): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_pull_requests', 20));
        $query = [
            'per_page' => min(100, $limit),
            'state' => $this->issueState($options['state'] ?? 'open'),
        ];
        foreach (['base', 'head'] as $key) {
            $value = $this->optionalString($options[$key] ?? null);
            if ($value !== null) {
                $query[$key] = $value;
            }
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/pulls', $query);
        $items = [];
        foreach (array_slice(is_array($payload) ? $payload : [], 0, $limit) as $raw) {
            if (is_array($raw)) {
                $items[] = $this->mapPullSummary($raw);
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'pull_requests' => $items,
            'truncated' => is_array($payload) && count($payload) > $limit,
            'result_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(IntegrationAccount $account, string $repository, int $number): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $number = $this->assertPositive($number, 'github_pr_not_found');
        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/pulls/'.$number);
        $filesPayload = $this->get($account, $this->repoPath($resolved['full_name']).'/pulls/'.$number.'/files', [
            'per_page' => min(100, (int) config('github.max_pr_files', 40)),
        ]);
        $maxFiles = (int) config('github.max_pr_files', 40);
        $files = [];
        foreach (array_slice(is_array($filesPayload) ? $filesPayload : [], 0, $maxFiles) as $raw) {
            if (is_array($raw)) {
                $files[] = $this->mapFileChange($raw, includePatch: false);
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'pull_request' => $this->mapPullDetail($payload),
            'files' => $files,
            'truncated' => count(is_array($filesPayload) ? $filesPayload : []) > $maxFiles,
            'result_count' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPullRequestDiff(IntegrationAccount $account, string $repository, int $number): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $number = $this->assertPositive($number, 'github_pr_not_found');
        $filesPayload = $this->get($account, $this->repoPath($resolved['full_name']).'/pulls/'.$number.'/files', [
            'per_page' => min(100, (int) config('github.max_pr_files', 40)),
        ]);
        $maxFiles = (int) config('github.max_pr_files', 40);
        $maxDiff = (int) config('github.max_diff_chars', 8000);
        $used = 0;
        $files = [];
        $truncated = false;
        foreach (array_slice(is_array($filesPayload) ? $filesPayload : [], 0, $maxFiles) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $mapped = $this->mapFileChange($raw);
            $patchLen = mb_strlen((string) ($mapped['patch'] ?? ''));
            if ($used + $patchLen > $maxDiff) {
                $remain = $maxDiff - $used;
                if ($remain > 0 && isset($mapped['patch'])) {
                    $mapped['patch'] = mb_substr((string) $mapped['patch'], 0, $remain);
                    $mapped['patch_truncated'] = true;
                    $files[] = $mapped;
                }
                $truncated = true;
                break;
            }
            $used += $patchLen;
            $files[] = $mapped;
        }

        if (count(is_array($filesPayload) ? $filesPayload : []) > $maxFiles) {
            $truncated = true;
        }

        return [
            'repository' => $resolved['full_name'],
            'pull_number' => $number,
            'files' => $files,
            'truncated' => $truncated,
            'result_count' => count($files),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listWorkflowRuns(IntegrationAccount $account, string $repository, array $options = []): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, (int) config('github.max_workflow_runs', 15));
        $query = ['per_page' => min(100, $limit)];
        foreach (['branch' => 'branch', 'status' => 'status', 'event' => 'event'] as $arg => $param) {
            $value = $this->optionalString($options[$arg] ?? null);
            if ($value !== null) {
                $query[$param] = $value;
            }
        }

        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/actions/runs', $query);
        $items = [];
        foreach (array_slice((array) ($payload['workflow_runs'] ?? []), 0, $limit) as $raw) {
            if (is_array($raw)) {
                $items[] = $this->mapWorkflowRun($raw);
            }
        }

        return [
            'repository' => $resolved['full_name'],
            'workflow_runs' => $items,
            'truncated' => ((int) ($payload['total_count'] ?? 0)) > count($items),
            'result_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkflowRun(IntegrationAccount $account, string $repository, int $runId): array
    {
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $runId = $this->assertPositive($runId, 'github_workflow_run_not_found');
        $payload = $this->get($account, $this->repoPath($resolved['full_name']).'/actions/runs/'.$runId);
        $jobsPayload = $this->get($account, $this->repoPath($resolved['full_name']).'/actions/runs/'.$runId.'/jobs', [
            'per_page' => min(100, (int) config('github.max_workflow_jobs', 20)),
        ]);
        $maxJobs = (int) config('github.max_workflow_jobs', 20);
        $jobs = [];
        foreach (array_slice((array) ($jobsPayload['jobs'] ?? []), 0, $maxJobs) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $jobs[] = [
                'id' => (int) ($raw['id'] ?? 0),
                'name' => (string) ($raw['name'] ?? ''),
                'status' => (string) ($raw['status'] ?? ''),
                'conclusion' => isset($raw['conclusion']) ? (string) $raw['conclusion'] : null,
                'started_at' => isset($raw['started_at']) ? (string) $raw['started_at'] : null,
                'completed_at' => isset($raw['completed_at']) ? (string) $raw['completed_at'] : null,
            ];
        }

        return [
            'repository' => $resolved['full_name'],
            'workflow_run' => $this->mapWorkflowRun($payload),
            'jobs' => $jobs,
            'truncated' => count((array) ($jobsPayload['jobs'] ?? [])) > $maxJobs,
            'result_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createIssue(IntegrationAccount $account, string $repository, array $input): array
    {
        $this->assertWriteAllowed('create_issue');
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $title = $this->boundedText($input['title'] ?? null, (int) config('github.max_title_chars', 256), required: true);
        $body = $this->boundedText($input['body'] ?? null, (int) config('github.max_body_chars', 4000));
        $payload = [
            'title' => $title,
        ];
        if ($body !== null) {
            $payload['body'] = $body;
        }
        $labels = $this->stringList($input['labels'] ?? [], (int) config('github.max_labels', 10));
        if ($labels !== []) {
            $payload['labels'] = $labels;
        }
        $assignees = $this->stringList($input['assignees'] ?? [], (int) config('github.max_assignees', 10));
        if ($assignees !== []) {
            $payload['assignees'] = $assignees;
        }

        $created = $this->post($account, $this->repoPath($resolved['full_name']).'/issues', $payload);

        return [
            'repository' => $resolved['full_name'],
            'issue' => $this->mapIssueSummary($created),
            'result_count' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commentIssue(IntegrationAccount $account, string $repository, int $number, string $body): array
    {
        $this->assertWriteAllowed('comment_issue');
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $number = $this->assertPositive($number, 'github_issue_not_found');
        $text = $this->boundedText($body, (int) config('github.max_body_chars', 4000), required: true);
        $created = $this->post($account, $this->repoPath($resolved['full_name']).'/issues/'.$number.'/comments', [
            'body' => $text,
        ]);

        return [
            'repository' => $resolved['full_name'],
            'issue_number' => $number,
            'comment' => [
                'id' => (int) ($created['id'] ?? 0),
                'html_url' => isset($created['html_url']) ? (string) $created['html_url'] : null,
            ],
            'result_count' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createBranch(IntegrationAccount $account, string $repository, string $branchName, ?string $fromRef = null): array
    {
        $this->assertWriteAllowed('create_branch');
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $branchName = $this->assertBranchName($branchName);
        $from = $fromRef !== null && trim($fromRef) !== ''
            ? $this->assertRef($fromRef)
            : (string) ($resolved['default_branch'] ?? config('github.default_branch_fallback', 'main'));

        try {
            $this->get($account, $this->repoPath($resolved['full_name']).'/git/ref/heads/'.rawurlencode($branchName));
            throw new IntegrationException('github_conflict', 'GitHub branch already exists.');
        } catch (IntegrationException $exception) {
            if ($exception->error !== 'github_ref_not_found') {
                throw $exception;
            }
        }

        $source = $this->get($account, $this->repoPath($resolved['full_name']).'/git/ref/heads/'.rawurlencode($from));
        $sha = (string) ($source['object']['sha'] ?? '');
        if ($sha === '') {
            $commit = $this->get($account, $this->repoPath($resolved['full_name']).'/commits/'.rawurlencode($from));
            $sha = (string) ($commit['sha'] ?? '');
        }
        if ($sha === '') {
            throw new IntegrationException('github_ref_not_found', 'GitHub source ref was not found.');
        }

        $created = $this->post($account, $this->repoPath($resolved['full_name']).'/git/refs', [
            'ref' => 'refs/heads/'.$branchName,
            'sha' => $sha,
        ]);

        return [
            'repository' => $resolved['full_name'],
            'branch' => $branchName,
            'sha' => (string) ($created['object']['sha'] ?? $sha),
            'from_ref' => $from,
            'result_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createPullRequest(IntegrationAccount $account, string $repository, array $input): array
    {
        $this->assertWriteAllowed('create_pull_request');
        $resolved = $this->resolveRepository($account, $repository);
        if (isset($resolved['ambiguous'])) {
            return $resolved;
        }

        $title = $this->boundedText($input['title'] ?? null, (int) config('github.max_title_chars', 256), required: true);
        $head = $this->assertBranchName((string) ($input['head'] ?? ''));
        $base = $this->optionalString($input['base'] ?? null)
            ?? (string) ($resolved['default_branch'] ?? config('github.default_branch_fallback', 'main'));
        $base = $this->assertBranchName($base);

        $ownerLogin = explode('/', $resolved['full_name'])[0];
        $existing = $this->get($account, $this->repoPath($resolved['full_name']).'/pulls', [
            'state' => 'open',
            'head' => $ownerLogin.':'.$head,
            'base' => $base,
            'per_page' => 5,
        ]);
        foreach (is_array($existing) ? $existing : [] as $raw) {
            if (is_array($raw) && (string) ($raw['head']['ref'] ?? '') === $head && (string) ($raw['base']['ref'] ?? '') === $base) {
                return [
                    'repository' => $resolved['full_name'],
                    'pull_request' => $this->mapPullSummary($raw),
                    'already_existed' => true,
                    'result_count' => 1,
                ];
            }
        }

        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
        ];
        $body = $this->boundedText($input['body'] ?? null, (int) config('github.max_body_chars', 4000));
        if ($body !== null) {
            $payload['body'] = $body;
        }
        if (array_key_exists('draft', $input)) {
            $payload['draft'] = (bool) $input['draft'];
        }

        $created = $this->post($account, $this->repoPath($resolved['full_name']).'/pulls', $payload);

        return [
            'repository' => $resolved['full_name'],
            'pull_request' => $this->mapPullSummary($created),
            'already_existed' => false,
            'result_count' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveRepository(IntegrationAccount $account, string $repository): array
    {
        $raw = trim($repository);
        if ($raw === '') {
            throw new IntegrationException('github_validation_failed', 'Repository is required.');
        }

        if (str_contains($raw, '/')) {
            $fullName = $this->assertFullName($raw);
            try {
                $payload = $this->get($account, $this->repoPath($fullName));

                return $this->mapRepository($payload);
            } catch (IntegrationException $exception) {
                if ($exception->error !== 'github_repository_not_found') {
                    throw $exception;
                }
            }
        }

        $listed = $this->listRepositories($account, ['max_results' => (int) config('github.max_repositories', 30)]);
        $needle = $this->normalizeName($raw);
        $exactFull = [];
        $exactName = [];
        $normalized = [];

        foreach ($listed['repositories'] as $repo) {
            $full = $this->normalizeName((string) $repo['full_name']);
            $name = $this->normalizeName((string) $repo['name']);
            if ($full === $needle) {
                $exactFull[] = $repo;
            } elseif ($name === $needle) {
                $exactName[] = $repo;
            } elseif (str_contains($full, $needle) || str_contains($name, $needle)) {
                $normalized[] = $repo;
            }
        }

        $matches = $exactFull !== [] ? $exactFull : ($exactName !== [] ? $exactName : $normalized);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new IntegrationException('github_repository_not_found', 'GitHub repository was not found.');
        }

        $max = (int) config('github.max_candidates', 8);

        return [
            'ambiguous' => true,
            'error' => 'github_repository_ambiguous',
            'candidates' => array_map(static fn (array $repo): array => [
                'full_name' => $repo['full_name'],
                'name' => $repo['name'],
                'private' => $repo['private'],
                'html_url' => $repo['html_url'] ?? null,
            ], array_slice($matches, 0, $max)),
            'truncated' => count($matches) > $max,
            'result_count' => min(count($matches), $max),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(IntegrationAccount $account, string $path, array $query = [], ?string $accept = null): array
    {
        return $this->send($account, 'GET', $path, query: $query, retrySafe: true, accept: $accept);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(IntegrationAccount $account, string $path, array $body): array
    {
        return $this->send($account, 'POST', $path, $body, retrySafe: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(
        IntegrationAccount $account,
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        bool $retrySafe = false,
        ?string $accept = null,
    ): array {
        $token = $this->credentials->getValidAccessToken($account);
        $url = rtrim((string) config('github.api_base_url'), '/').$path;
        $retries = $retrySafe ? max(0, (int) config('github.get_retries', 1)) : 0;

        $request = $this->http($accept)
            ->withToken($token)
            ->retry($retries, 200, throw: false);

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($url, $body),
                default => throw new IntegrationException('github_unavailable', 'Unsupported GitHub method.'),
            };
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->logFailure($method, 'github_unavailable');
            throw new IntegrationException('github_unavailable', 'GitHub is unavailable.', true);
        }

        if (! $response->successful()) {
            $this->failFromResponse($account, $response, $path);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function http(?string $accept = null): PendingRequest
    {
        return Http::timeout((int) config('github.timeout', 10))
            ->connectTimeout((int) config('github.connect_timeout', 5))
            ->accept($accept ?: 'application/vnd.github+json')
            ->asJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => (string) config('github.api_version', '2022-11-28'),
                'User-Agent' => (string) config('github.user_agent', 'Jarvis-OwlSolutions'),
            ]);
    }

    private function failFromResponse(IntegrationAccount $account, Response $response, string $path): never
    {
        $status = $response->status();
        $code = $this->normalizeError($account, $status, $response, $path);
        $this->logFailure('http', $code, $status);

        $context = [];
        if ($code === 'github_rate_limited') {
            $reset = $response->header('X-RateLimit-Reset');
            $remaining = $response->header('X-RateLimit-Remaining');
            $retryAfter = $response->header('Retry-After');
            $context['remaining'] = is_numeric($remaining) ? (int) $remaining : 0;
            if (is_numeric($reset)) {
                $context['reset_at'] = gmdate('c', (int) $reset);
            } elseif (is_numeric($retryAfter)) {
                $context['reset_at'] = now()->addSeconds((int) $retryAfter)->toIso8601String();
            }
        }

        throw new IntegrationException(
            $code,
            'GitHub request failed.',
            in_array($code, ['github_rate_limited', 'github_unavailable'], true),
            $context,
        );
    }

    private function normalizeError(IntegrationAccount $account, int $status, Response $response, string $path): string
    {
        $message = strtolower((string) ($response->json('message') ?? ''));
        $remaining = $response->header('X-RateLimit-Remaining');

        if ($status === 401 || str_contains($message, 'bad credentials')) {
            $this->accounts->markRevoked($account);
            $account->forceFill([
                'last_error_code' => 'github_token_revoked',
                'last_error_at' => now(),
            ])->save();

            return 'github_token_revoked';
        }

        if ($status === 403 && (str_contains($message, 'rate limit') || $remaining === '0')) {
            return 'github_rate_limited';
        }

        if ($status === 429) {
            return 'github_rate_limited';
        }

        if ($status === 403 && (str_contains($message, 'scope') || str_contains($message, 'resource not accessible'))) {
            return 'github_scope_required';
        }

        if ($status === 403) {
            return 'github_forbidden';
        }

        if ($status === 404) {
            return $this->notFoundCode($path);
        }

        if ($status === 409) {
            return 'github_conflict';
        }

        if ($status === 422) {
            if (str_contains($message, 'already exists') || str_contains($message, 'reference already exists')) {
                return 'github_conflict';
            }

            return 'github_validation_failed';
        }

        if ($status >= 500) {
            return 'github_unavailable';
        }

        return 'github_unavailable';
    }

    private function notFoundCode(string $path): string
    {
        if (str_contains($path, '/actions/runs/')) {
            return 'github_workflow_run_not_found';
        }
        if (str_contains($path, '/pulls/')) {
            return 'github_pr_not_found';
        }
        if (str_contains($path, '/issues/')) {
            return 'github_issue_not_found';
        }
        if (str_contains($path, '/contents/')) {
            return 'github_file_not_found';
        }
        if (str_contains($path, '/git/ref') || str_contains($path, '/commits/') || str_contains($path, '/compare/')) {
            return 'github_ref_not_found';
        }
        if (preg_match('#/repos/[^/]+/[^/]+$#', $path) === 1) {
            return 'github_repository_not_found';
        }

        return 'github_repository_not_found';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapRepository(array $raw): array
    {
        return [
            'full_name' => (string) ($raw['full_name'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'owner' => (string) ($raw['owner']['login'] ?? ''),
            'private' => (bool) ($raw['private'] ?? false),
            'description' => $this->truncate(isset($raw['description']) ? (string) $raw['description'] : null, (int) config('github.max_description_chars', 240)),
            'default_branch' => (string) ($raw['default_branch'] ?? config('github.default_branch_fallback', 'main')),
            'archived' => (bool) ($raw['archived'] ?? false),
            'fork' => (bool) ($raw['fork'] ?? false),
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapRepositoryDetail(array $raw): array
    {
        $mapped = $this->mapRepository($raw);
        $permissions = is_array($raw['permissions'] ?? null) ? $raw['permissions'] : [];

        return array_merge($mapped, [
            'language' => isset($raw['language']) ? (string) $raw['language'] : null,
            'visibility' => isset($raw['visibility']) ? (string) $raw['visibility'] : (($raw['private'] ?? false) ? 'private' : 'public'),
            'open_issues_count' => (int) ($raw['open_issues_count'] ?? 0),
            'pushed_at' => isset($raw['pushed_at']) ? (string) $raw['pushed_at'] : null,
            'permissions' => [
                'admin' => (bool) ($permissions['admin'] ?? false),
                'push' => (bool) ($permissions['push'] ?? false),
                'pull' => (bool) ($permissions['pull'] ?? false),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapCommitSummary(array $raw): array
    {
        $sha = (string) ($raw['sha'] ?? '');
        $commit = is_array($raw['commit'] ?? null) ? $raw['commit'] : [];
        $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
        $login = (string) ($raw['author']['login'] ?? $author['name'] ?? '');

        return [
            'sha' => $sha,
            'short_sha' => substr($sha, 0, 7),
            'message' => $this->truncate((string) ($commit['message'] ?? ''), 400) ?? '',
            'author' => $login,
            'timestamp' => (string) ($author['date'] ?? ''),
            'parents_count' => count((array) ($raw['parents'] ?? [])),
            'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapFileChange(array $raw, bool $includePatch = true): array
    {
        $mapped = [
            'filename' => (string) ($raw['filename'] ?? ''),
            'status' => (string) ($raw['status'] ?? ''),
            'additions' => (int) ($raw['additions'] ?? 0),
            'deletions' => (int) ($raw['deletions'] ?? 0),
            'changes' => (int) ($raw['changes'] ?? 0),
        ];

        if ($includePatch && isset($raw['patch'])) {
            $maxPatch = (int) config('github.max_patch_chars', 2000);
            $patch = (string) $raw['patch'];
            $mapped['patch_truncated'] = mb_strlen($patch) > $maxPatch;
            $mapped['patch'] = $this->truncate($patch, $maxPatch);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapIssueSummary(array $raw): array
    {
        $labels = [];
        foreach (array_slice((array) ($raw['labels'] ?? []), 0, (int) config('github.max_labels', 10)) as $label) {
            if (is_array($label) && isset($label['name'])) {
                $labels[] = (string) $label['name'];
            } elseif (is_string($label)) {
                $labels[] = $label;
            }
        }

        $assignees = [];
        foreach (array_slice((array) ($raw['assignees'] ?? []), 0, (int) config('github.max_assignees', 10)) as $assignee) {
            if (is_array($assignee) && isset($assignee['login'])) {
                $assignees[] = (string) $assignee['login'];
            }
        }

        return [
            'number' => (int) ($raw['number'] ?? 0),
            'title' => (string) ($raw['title'] ?? ''),
            'state' => (string) ($raw['state'] ?? ''),
            'author' => (string) ($raw['user']['login'] ?? ''),
            'assignees' => $assignees,
            'labels' => $labels,
            'created_at' => isset($raw['created_at']) ? (string) $raw['created_at'] : null,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'comments' => (int) ($raw['comments'] ?? 0),
            'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapIssueDetail(array $raw): array
    {
        $summary = $this->mapIssueSummary($raw);
        $maxBody = (int) config('github.max_issue_body_chars', 4000);
        $body = (string) ($raw['body'] ?? '');

        return array_merge($summary, [
            'body' => $this->truncate($body, $maxBody),
            'body_truncated' => mb_strlen($body) > $maxBody,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapPullSummary(array $raw): array
    {
        return [
            'number' => (int) ($raw['number'] ?? 0),
            'title' => (string) ($raw['title'] ?? ''),
            'state' => (string) ($raw['state'] ?? ''),
            'draft' => (bool) ($raw['draft'] ?? false),
            'author' => (string) ($raw['user']['login'] ?? ''),
            'head' => (string) ($raw['head']['ref'] ?? ''),
            'base' => (string) ($raw['base']['ref'] ?? ''),
            'mergeable' => array_key_exists('mergeable', $raw) ? $raw['mergeable'] : null,
            'mergeable_state' => isset($raw['mergeable_state']) ? (string) $raw['mergeable_state'] : null,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapPullDetail(array $raw): array
    {
        return array_merge($this->mapPullSummary($raw), [
            'commits' => (int) ($raw['commits'] ?? 0),
            'changed_files' => (int) ($raw['changed_files'] ?? 0),
            'additions' => (int) ($raw['additions'] ?? 0),
            'deletions' => (int) ($raw['deletions'] ?? 0),
            'merged' => (bool) ($raw['merged'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapWorkflowRun(array $raw): array
    {
        return [
            'id' => (int) ($raw['id'] ?? 0),
            'name' => (string) ($raw['name'] ?? $raw['display_title'] ?? ''),
            'workflow_name' => (string) ($raw['name'] ?? ''),
            'branch' => (string) ($raw['head_branch'] ?? ''),
            'sha' => (string) ($raw['head_sha'] ?? ''),
            'status' => (string) ($raw['status'] ?? ''),
            'conclusion' => isset($raw['conclusion']) ? (string) $raw['conclusion'] : null,
            'event' => (string) ($raw['event'] ?? ''),
            'created_at' => isset($raw['created_at']) ? (string) $raw['created_at'] : null,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'html_url' => isset($raw['html_url']) ? (string) $raw['html_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decodeContent(array $payload): string
    {
        $encoding = (string) ($payload['encoding'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
            if ($decoded === false) {
                throw new IntegrationException('github_unavailable', 'GitHub file content could not be decoded.');
            }
        } else {
            $decoded = $content;
        }

        if ($decoded === '' && (int) ($payload['size'] ?? 0) > 0) {
            throw new IntegrationException('github_validation_failed', 'GitHub file is too large to read through the contents API.');
        }

        if (str_contains($decoded, "\0")) {
            throw new IntegrationException('github_validation_failed', 'GitHub file is binary and was not loaded.');
        }

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            throw new IntegrationException('github_validation_failed', 'GitHub file is not valid text.');
        }

        return $decoded;
    }

    private function assertWriteAllowed(string $operation): void
    {
        $allowed = config('github.allowed_write_operations', []);
        if (! is_array($allowed) || ! in_array($operation, $allowed, true)) {
            throw new IntegrationException('github_forbidden', 'This GitHub write operation is not enabled.');
        }
    }

    private function repoPath(string $fullName): string
    {
        [$owner, $name] = explode('/', $this->assertFullName($fullName), 2);

        return '/repos/'.rawurlencode($owner).'/'.rawurlencode($name);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function assertFullName(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value) !== 1) {
            throw new IntegrationException('github_validation_failed', 'Repository full name is invalid.');
        }

        return $value;
    }

    private function assertRef(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/[\s]/', $value) === 1) {
            throw new IntegrationException('github_validation_failed', 'Git ref is invalid.');
        }

        return $value;
    }

    private function assertPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new IntegrationException('github_validation_failed', 'File path is invalid.');
        }

        return $path;
    }

    private function assertBranchName(string $name): string
    {
        $name = trim($name);
        $name = str_starts_with($name, 'refs/heads/') ? substr($name, 11) : $name;
        if ($name === '' || strlen($name) > 255) {
            throw new IntegrationException('github_validation_failed', 'Branch name is invalid.');
        }
        if (str_starts_with($name, '/') || str_ends_with($name, '/') || str_ends_with($name, '.lock')) {
            throw new IntegrationException('github_validation_failed', 'Branch name is invalid.');
        }
        if (str_contains($name, '..') || str_contains($name, '//') || str_contains($name, '@{')) {
            throw new IntegrationException('github_validation_failed', 'Branch name is invalid.');
        }
        if (preg_match('/[\s~^:?*\[\\\\\x00-\x1F\x7F]/', $name) === 1) {
            throw new IntegrationException('github_validation_failed', 'Branch name is invalid.');
        }

        return $name;
    }

    private function assertPositive(int $value, string $error): int
    {
        if ($value < 1) {
            throw new IntegrationException($error, 'GitHub resource number is invalid.');
        }

        return $value;
    }

    private function issueState(mixed $value): string
    {
        $state = strtolower(trim((string) $value));

        return in_array($state, ['open', 'closed', 'all'], true) ? $state : 'open';
    }

    /**
     * @param  array<string, mixed>  $repo
     */
    private function matchesQuery(array $repo, string $needle): bool
    {
        return str_contains($this->normalizeName((string) $repo['full_name']), $needle)
            || str_contains($this->normalizeName((string) $repo['name']), $needle)
            || str_contains($this->normalizeName((string) ($repo['description'] ?? '')), $needle);
    }

    private function normalizeName(string $value): string
    {
        return strtolower(preg_replace('/[\s_\-]+/', '', $value) ?? $value);
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw, int $max): array
    {
        if (! is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [(string) $raw];
        }
        if (count($raw) > $max) {
            throw new IntegrationException('github_validation_failed', 'Too many list values.');
        }

        $items = [];
        foreach ($raw as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private function boundedText(mixed $value, int $max, bool $required = false): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            if ($required) {
                throw new IntegrationException('github_validation_failed', 'A required text field is empty.');
            }

            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new IntegrationException('github_validation_failed', 'A text field exceeds the configured maximum.');
        }

        return $text;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    private function bound(?int $requested, int $configured): int
    {
        $limit = $configured > 0 ? $configured : 1;
        if ($requested === null || $requested < 1) {
            return $limit;
        }

        return min($requested, $limit);
    }

    private function logFailure(string $action, string $code, ?int $status = null): void
    {
        Log::info('github api', [
            'provider' => 'github',
            'action' => $action,
            'success' => false,
            'error_code' => $code,
            'http_status' => $status,
        ]);
    }
}
