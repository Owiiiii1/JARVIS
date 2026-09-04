<?php

namespace App\Services\Groups;

use App\Enums\ProjectStatus;
use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use App\Enums\TelegramGroupStatus;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Project;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupKnowledge;
use App\Models\TelegramGroupParticipant;
use App\Models\User;
use App\Services\Groups\DTO\GroupSearchRequest;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Groups\Exceptions\GroupSearchException;
use App\Services\Memory\MemoryKeyNormalizer;
use App\Services\Projects\ProjectContextService;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GroupKnowledgeSearchService
{
    public function __construct(
        private readonly GroupTimeRangeService $ranges,
        private readonly GroupResolver $resolver,
        private readonly GroupAnalysisCoverageService $coverage,
        private readonly GroupAnalysisRunService $analysisRuns,
        private readonly ProjectContextService $projects,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(GroupSearchRequest $request): array
    {
        $startedAt = microtime(true);
        $this->assertCanSearch($request->user);

        $query = trim($request->query);
        $tokens = array_slice(MemoryKeyNormalizer::tokens($query, 2), 0, (int) config('group_search.max_query_tokens'));
        $types = $this->normalizeTypes($request->types);
        $rangePreset = $this->normalizeRange($request->range, $request->dateFrom, $request->dateTo);

        $project = null;
        if (filled($request->projectHint)) {
            $project = $this->resolveProject($request->user, (string) $request->projectHint);
        }

        $groups = $this->candidateGroups($request, $project);
        $resolved = $this->resolveGroups($groups, $request);

        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var list<TelegramGroup> $selected */
        $selected = $resolved['groups'];
        $maxGroups = (int) config('group_search.max_groups');
        $selected = array_slice($selected, 0, $maxGroups);

        $totalRawBudget = (int) config('group_search.max_total_raw_snippets');
        $usedRaw = 0;
        $queued = 0;
        $payloadGroups = [];

        foreach ($selected as $group) {
            $groupRange = $this->rangeForGroup($group, $rangePreset, $request);
            $coverage = $this->coverage->coverage($group, $groupRange);
            $knowledge = $this->knowledgeForGroup($group, $types, $tokens, $groupRange, $request->limit);
            $needsRaw = $this->needsRawFallback($request, $knowledge, $coverage, $tokens, $group);
            $raw = [];

            if ($needsRaw && $usedRaw < $totalRawBudget) {
                $remaining = $totalRawBudget - $usedRaw;
                $raw = $this->rawForGroup($group, $tokens, $groupRange, $coverage, $remaining);
                $usedRaw += count($raw);
            }

            if ($this->shouldQueueAnalysis($coverage, $knowledge, (int) $coverage['raw_messages'])) {
                if ($this->queueAnalysis($request->user, $group, $groupRange)) {
                    $queued++;
                    $coverage['status'] = 'queued';
                    $coverage['queued'] = true;
                }
            }

            $payloadGroups[] = $this->presentGroup($group, $groupRange, $coverage, $knowledge, $raw);
        }

        $empty = $this->isEmpty($payloadGroups);

        Log::info('group knowledge search', [
            'tool' => 'search_group_knowledge',
            'user_id' => $request->user->id,
            'groups_searched' => count($payloadGroups),
            'knowledge_count' => array_sum(array_map(
                static fn (array $row): int => (int) ($row['coverage']['knowledge_items'] ?? 0),
                $payloadGroups,
            )),
            'raw_snippet_count' => $usedRaw,
            'queued_analysis_count' => $queued,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'success' => true,
            'query' => $query,
            'groups' => $payloadGroups,
            'message' => $empty
                ? 'No matching group knowledge/raw messages in requested scope.'
                : null,
        ];
    }

    private function assertCanSearch(User $user): void
    {
        if (! $user->isActive()
            || $user->role !== UserRole::Owner
            || ! $user->canUseCapability(UserCapability::GROUP_ANALYSIS)) {
            throw new GroupSearchException('forbidden', 'Group knowledge search is owner-only.');
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeTypes(array $types): array
    {
        $allowed = array_map(
            static fn (TelegramGroupKnowledgeType $type): string => $type->value,
            TelegramGroupKnowledgeType::cases(),
        );
        $defaults = (array) config('group_search.default_types');
        $selected = $types === [] ? $defaults : $types;
        $normalized = [];

        foreach ($selected as $type) {
            $value = is_string($type) ? trim($type) : '';
            if (in_array($value, $allowed, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized === [] ? $defaults : array_values(array_unique($normalized));
    }

    /**
     * @return array{preset: string|null, from: string|null, to: string|null}
     */
    private function normalizeRange(?string $range, ?string $dateFrom, ?string $dateTo): array
    {
        $preset = $range !== null && $range !== '' ? trim($range) : null;

        if ($preset === null && (filled($dateFrom) || filled($dateTo))) {
            $preset = 'custom';
        }

        if ($preset === null) {
            return ['preset' => null, 'from' => null, 'to' => null];
        }

        if (! in_array($preset, ['today', 'yesterday', 'last_7_days', 'custom'], true)) {
            throw new GroupSearchException('needs_clarification', 'Unknown range. Use today, yesterday, last_7_days, or custom dates.');
        }

        if ($preset === 'custom') {
            if (! filled($dateFrom) || ! filled($dateTo)) {
                throw new GroupSearchException('needs_clarification', 'Custom range requires date_from and date_to as Y-m-d.');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateFrom) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateTo) !== 1) {
                throw new GroupSearchException('needs_clarification', 'Dates must use Y-m-d.');
            }
        }

        return ['preset' => $preset, 'from' => $dateFrom, 'to' => $dateTo];
    }

    private function resolveProject(User $user, string $hint): Project
    {
        $matches = $this->projects->resolve($user, $hint);

        if ($matches === []) {
            throw new GroupSearchException('project_not_found', 'Project was not found.');
        }

        if (count($matches) > 1) {
            throw new GroupSearchException('ambiguous_project', json_encode(array_map(
                static fn (Project $project): array => [
                    'name' => $project->name,
                    'status' => $project->status instanceof ProjectStatus ? $project->status->value : (string) $project->status,
                ],
                $matches,
            ), JSON_UNESCAPED_UNICODE) ?: '[]');
        }

        return $matches[0];
    }

    /**
     * @return Collection<int, TelegramGroup>
     */
    private function candidateGroups(GroupSearchRequest $request, ?Project $project): Collection
    {
        $explicitGroup = filled($request->groupHint);
        $historical = in_array($request->range, ['yesterday', 'last_7_days', 'custom'], true);
        $includeArchived = $explicitGroup
            || $historical
            || (bool) config('group_search.include_archived_by_default');

        $query = TelegramGroup::query()->orderBy('title')->orderBy('id');

        if ($project !== null) {
            $query->whereIn('id', $project->telegramGroups()->select('telegram_groups.id'));
        }

        if (! $includeArchived) {
            $query->whereIn('status', [
                TelegramGroupStatus::Connected,
                TelegramGroupStatus::Restricted,
            ]);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, TelegramGroup>  $pool
     * @return array{groups: list<TelegramGroup>}|array<string, mixed>
     */
    private function resolveGroups(Collection $pool, GroupSearchRequest $request): array
    {
        if (! filled($request->groupHint)) {
            return ['groups' => $pool->all()];
        }

        $resolved = $this->resolver->resolve($pool, (string) $request->groupHint);

        if (isset($resolved['match'])) {
            return ['groups' => [$resolved['match']]];
        }

        if (isset($resolved['candidates'])) {
            return [
                'success' => true,
                'error' => 'ambiguous_group',
                'query' => $request->query,
                'candidates' => array_map(
                    static fn (TelegramGroup $group): array => [
                        'name' => $group->title,
                        'status' => $group->status->value,
                    ],
                    $resolved['candidates'],
                ),
            ];
        }

        return [
            'success' => false,
            'error' => 'group_not_found',
            'query' => $request->query,
        ];
    }

    /**
     * @param  array{preset: string|null, from: string|null, to: string|null}  $preset
     * @return array{from: CarbonImmutable, to: CarbonImmutable}|null
     */
    private function rangeForGroup(TelegramGroup $group, array $preset, GroupSearchRequest $request): ?array
    {
        if ($preset['preset'] === null) {
            return null;
        }

        $now = $request->now;

        try {
            return match ($preset['preset']) {
                'today' => $now !== null
                    ? $this->ranges->today($group, $now)
                    : $this->ranges->today($group),
                'yesterday' => $now !== null
                    ? $this->ranges->yesterday($group, $now)
                    : $this->ranges->yesterday($group),
                'last_7_days' => $now !== null
                    ? $this->ranges->lastDays($group, 7, $now)
                    : $this->ranges->lastDays($group, 7),
                'custom' => $this->ranges->customLocalDates($group, (string) $preset['from'], (string) $preset['to']),
                default => null,
            };
        } catch (GroupAnalysisException $exception) {
            throw new GroupSearchException('needs_clarification', $exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $tokens
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     * @return list<TelegramGroupKnowledge>
     */
    private function knowledgeForGroup(
        TelegramGroup $group,
        array $types,
        array $tokens,
        ?array $range,
        ?int $limit,
    ): array {
        $max = min(
            (int) config('group_search.max_knowledge_per_group'),
            max(1, $limit ?? (int) config('group_search.max_knowledge_per_group')),
        );
        $candidateLimit = (int) config('group_search.candidate_limit');

        $rows = TelegramGroupKnowledge::query()
            ->where('telegram_group_id', $group->id)
            ->where('status', TelegramGroupKnowledgeStatus::Active)
            ->whereIn('type', $types)
            ->with(['sources.message'])
            ->when($range !== null, function ($query) use ($range): void {
                $query->where(function ($builder) use ($range): void {
                    $builder->where(function ($inner) use ($range): void {
                        $inner->whereNotNull('valid_from')
                            ->whereNotNull('valid_until')
                            ->where('valid_from', '<', $range['to'])
                            ->where('valid_until', '>', $range['from']);
                    })->orWhere(function ($inner) use ($range): void {
                        $inner->whereNull('valid_from')
                            ->whereNotNull('generated_at')
                            ->where('generated_at', '>=', $range['from'])
                            ->where('generated_at', '<', $range['to']);
                    });
                });
            })
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get();

        $ranked = $this->rankKnowledge($rows, $tokens);

        if ($tokens !== []) {
            $ranked = $ranked->filter(function (TelegramGroupKnowledge $row) use ($tokens): bool {
                $structured = is_array($row->structured_data) ? json_encode($row->structured_data) : '';
                $haystack = mb_strtolower($row->title.' '.$row->content.' '.$structured);

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $ranked->take($max)->all();
    }

    /**
     * @param  Collection<int, TelegramGroupKnowledge>  $rows
     * @param  list<string>  $tokens
     * @return Collection<int, TelegramGroupKnowledge>
     */
    private function rankKnowledge(Collection $rows, array $tokens): Collection
    {
        if ($tokens === []) {
            return $rows->values();
        }

        return $rows
            ->sortByDesc(function (TelegramGroupKnowledge $row) use ($tokens): float {
                $structured = is_array($row->structured_data) ? json_encode($row->structured_data) : '';
                $haystack = mb_strtolower($row->title.' '.$row->content.' '.$structured);
                $hits = 0;

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $hits++;
                    }
                }

                $tokenScore = $hits / max(1, count($tokens));

                return $tokenScore + ((float) $row->confidence * 0.15);
            })
            ->values();
    }

    /**
     * @param  list<TelegramGroupKnowledge>  $knowledge
     * @param  array<string, mixed>  $coverage
     * @param  list<string>  $tokens
     */
    private function needsRawFallback(
        GroupSearchRequest $request,
        array $knowledge,
        array $coverage,
        array $tokens,
        TelegramGroup $group,
    ): bool {
        if ($request->includeRawIfNeeded) {
            return true;
        }

        if ($knowledge === []) {
            return true;
        }

        if (($coverage['stale'] ?? false) === true) {
            return true;
        }

        return $this->participantTokens($group, $tokens) !== [];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function participantTokens(TelegramGroup $group, array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $names = TelegramGroupParticipant::query()
            ->where('telegram_group_id', $group->id)
            ->get(['display_name', 'username', 'first_name', 'last_name'])
            ->flatMap(static fn (TelegramGroupParticipant $row): array => array_filter([
                $row->display_name,
                $row->username,
                $row->first_name,
                $row->last_name,
            ]))
            ->map(static fn (string $name): string => mb_strtolower($name))
            ->all();

        $matched = [];

        foreach ($tokens as $token) {
            if (preg_match('/^\d+$/', $token) === 1) {
                continue;
            }

            foreach ($names as $name) {
                if (str_contains($name, $token)) {
                    $matched[] = $token;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * @param  list<string>  $tokens
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     * @param  array<string, mixed>  $coverage
     * @return list<array<string, mixed>>
     */
    private function rawForGroup(
        TelegramGroup $group,
        array $tokens,
        ?array $range,
        array $coverage,
        int $remainingBudget,
    ): array {
        $perGroup = min(
            (int) config('group_search.max_raw_snippets_per_group'),
            max(0, $remainingBudget),
        );

        if ($perGroup === 0) {
            return [];
        }

        $lookbackDays = (int) config('group_search.raw_unscoped_lookback_days');
        $candidateLimit = (int) config('group_search.candidate_limit');
        $participantTokens = $this->participantTokens($group, $tokens);
        $staleAfter = isset($coverage['latest_completed_at'])
            ? CarbonImmutable::parse((string) $coverage['latest_completed_at'])
            : null;

        $messages = Message::query()
            ->where('telegram_group_id', $group->id)
            ->when($range !== null, function ($query) use ($range): void {
                $query->where('occurred_at', '>=', $range['from'])
                    ->where('occurred_at', '<', $range['to']);
            }, function ($query) use ($lookbackDays): void {
                $query->where('occurred_at', '>=', now()->subDays(max(1, $lookbackDays)));
            })
            ->when(($coverage['stale'] ?? false) === true && $staleAfter !== null && $tokens === [], function ($query) use ($staleAfter): void {
                $query->where(function ($builder) use ($staleAfter): void {
                    $builder->where('occurred_at', '>', $staleAfter)
                        ->orWhere('created_at', '>', $staleAfter);
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get();

        $ranked = $messages
            ->sortByDesc(function (Message $message) use ($tokens, $participantTokens): float {
                $haystack = mb_strtolower(trim((string) $message->body).' '.(string) $message->sender_name.' '.(string) $message->sender_username);
                $hits = 0;

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $hits++;
                    }
                }

                foreach ($participantTokens as $token) {
                    $sender = mb_strtolower((string) $message->sender_name.' '.(string) $message->sender_username);
                    if (str_contains($sender, $token)) {
                        $hits += 2;
                    }
                }

                return $hits;
            })
            ->filter(function (Message $message) use ($tokens, $participantTokens): bool {
                if ($tokens === []) {
                    return trim((string) $message->body) !== '';
                }

                $haystack = mb_strtolower(trim((string) $message->body).' '.(string) $message->sender_name.' '.(string) $message->sender_username);

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        return true;
                    }
                }

                foreach ($participantTokens as $token) {
                    if (str_contains(mb_strtolower((string) $message->sender_name), $token)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->take($perGroup);

        $maxChars = (int) config('group_search.max_snippet_chars');

        return $ranked->map(function (Message $message) use ($group, $maxChars): array {
            $body = trim((string) $message->body);
            if (mb_strlen($body) > $maxChars) {
                $body = mb_substr($body, 0, $maxChars).'…';
            }

            return [
                'group' => $group->title,
                'sender' => $message->sender_name,
                'occurred_at_local' => $this->localTimestamp($group, $message->occurred_at),
                'snippet' => $body,
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @param  list<TelegramGroupKnowledge>  $knowledge
     */
    private function shouldQueueAnalysis(array $coverage, array $knowledge, int $rawCount): bool
    {
        if (! (bool) config('group_search.queue_missing_analysis')) {
            return false;
        }

        if ($rawCount < 1) {
            return false;
        }

        if (($coverage['queued'] ?? false) === true) {
            return false;
        }

        if (($coverage['status'] ?? null) === 'available') {
            return false;
        }

        if (($coverage['stale'] ?? false) === true) {
            return true;
        }

        return ($coverage['status'] ?? null) === 'missing' && $knowledge === [];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     */
    private function queueAnalysis(User $user, TelegramGroup $group, ?array $range): bool
    {
        if ($range === null) {
            $range = $this->ranges->lastDays($group, 7);
        }

        try {
            $this->analysisRuns->queue($user, $group, $range['from'], $range['to']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     * @param  array<string, mixed>  $coverage
     * @param  list<TelegramGroupKnowledge>  $knowledge
     * @param  list<array<string, mixed>>  $raw
     * @return array<string, mixed>
     */
    private function presentGroup(
        TelegramGroup $group,
        ?array $range,
        array $coverage,
        array $knowledge,
        array $raw,
    ): array {
        $timezone = $this->ranges->timezone($group)->getName();
        $buckets = [
            'summary' => null,
            'decisions' => [],
            'tasks' => [],
            'events' => [],
        ];

        foreach ($knowledge as $row) {
            $item = $this->presentKnowledge($group, $row);

            match ($row->type) {
                TelegramGroupKnowledgeType::Summary => $buckets['summary'] ??= $item['content'],
                TelegramGroupKnowledgeType::Decision => $buckets['decisions'][] = $item,
                TelegramGroupKnowledgeType::Task => $buckets['tasks'][] = $item,
                TelegramGroupKnowledgeType::EventFact => $buckets['events'][] = $item,
            };
        }

        return [
            'name' => $group->title,
            'timezone' => $timezone,
            'range_local' => $this->localRangeLabel($group, $range),
            'coverage' => [
                'raw_messages' => $coverage['raw_messages'],
                'analysis_runs' => $coverage['analysis_runs'],
                'knowledge_items' => $coverage['knowledge_items'],
            ],
            'analysis_status' => $coverage['status'],
            'summary' => $buckets['summary'],
            'decisions' => $buckets['decisions'],
            'tasks' => $buckets['tasks'],
            'events' => $buckets['events'],
            'raw_snippets' => $raw,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentKnowledge(TelegramGroup $group, TelegramGroupKnowledge $row): array
    {
        $maxSources = (int) config('group_search.max_source_snippets');
        $maxChars = (int) config('group_search.max_snippet_chars');
        $structured = is_array($row->structured_data) ? $row->structured_data : [];
        $sources = $row->sources
            ->take($maxSources)
            ->map(function ($source) use ($group, $maxChars): array {
                $message = $source->message;
                $snippet = trim((string) ($message?->body ?? ''));
                if (mb_strlen($snippet) > $maxChars) {
                    $snippet = mb_substr($snippet, 0, $maxChars).'…';
                }

                return [
                    'group' => $group->title,
                    'sender' => $message?->sender_name,
                    'occurred_at_local' => $this->localTimestamp($group, $message?->occurred_at),
                    'snippet' => $snippet !== '' ? $snippet : null,
                ];
            })
            ->all();

        $payload = [
            'content' => $row->content,
            'confidence' => $row->confidence,
            'occurred_at_local' => $this->localTimestamp($group, $row->generated_at),
            'sources' => $sources,
        ];

        if ($row->type === TelegramGroupKnowledgeType::Task) {
            $payload['assignee_text'] = $structured['assignee_text'] ?? null;
            $payload['due_date_local'] = $structured['due_at_local'] ?? $structured['due_date_local'] ?? null;
            $payload['status'] = $structured['status'] ?? $row->status->value;
        }

        return $payload;
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|null  $range
     */
    private function localRangeLabel(TelegramGroup $group, ?array $range): ?string
    {
        if ($range === null) {
            return null;
        }

        $tz = $this->ranges->timezone($group)->getName();
        $from = $range['from']->setTimezone($tz)->toDateString();
        $to = $range['to']->setTimezone($tz)->subSecond()->toDateString();

        return $from === $to ? $from : $from.'…'.$to;
    }

    private function localTimestamp(TelegramGroup $group, mixed $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $moment = $time instanceof CarbonImmutable
            ? $time
            : CarbonImmutable::parse((string) $time);

        return $moment->setTimezone($this->ranges->timezone($group)->getName())->toIso8601String();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function isEmpty(array $groups): bool
    {
        foreach ($groups as $group) {
            if (filled($group['summary'] ?? null)
                || ($group['decisions'] ?? []) !== []
                || ($group['tasks'] ?? []) !== []
                || ($group['events'] ?? []) !== []
                || ($group['raw_snippets'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }
}
