<?php

namespace App\Services\Synthesis;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\ProjectStatus;
use App\Enums\SynthesisType;
use App\Models\KnowledgeEntity;
use App\Models\User;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Synthesis\DTO\SynthesisResult;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Users\UserCapability;
use Throwable;

final class CrossSourceSynthesisService
{
    public function __construct(
        private readonly SynthesisFactCollector $collector = new SynthesisFactCollector,
        private readonly SynthesisDeduplicator $dedupe = new SynthesisDeduplicator,
        private readonly WaitingForResolver $waiting = new WaitingForResolver,
        private readonly CommitmentResolver $commitments = new CommitmentResolver,
        private readonly ProjectAttentionResolver $attention = new ProjectAttentionResolver,
        private readonly SynthesisChangeAssembler $changes = new SynthesisChangeAssembler,
        private readonly SynthesisRanker $ranker = new SynthesisRanker,
        private readonly SynthesisConflictDetector $conflicts = new SynthesisConflictDetector,
        private readonly SynthesisCache $cache = new SynthesisCache,
        private readonly SynthesisClock $clock = new SynthesisClock,
        private readonly ?SynthesisNarrativeService $narrative = null,
    ) {}

    public function synthesize(SynthesisScope $scope): SynthesisResult
    {
        if (! $scope->user->isActive()) {
            throw new Exceptions\SynthesisException('user_inactive', 'User is not active.');
        }

        $compute = fn (): SynthesisResult => $this->compute($scope);

        if ($scope->skipCache || $scope->now !== null) {
            return $compute();
        }

        $cached = $this->cache->remember($scope->user, $scope->cacheKeyParts(), $compute);

        return $cached instanceof SynthesisResult ? $cached : $compute();
    }

    public function contextBlock(User $user, ?WorkingContext $working): ?string
    {
        $project = trim((string) ($working?->activeProject ?? ''));

        if ($project === '' || ! $user->isActive() || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return null;
        }

        try {
            $result = $this->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::ProjectStatus,
                projectName: $project,
                windowDays: 7,
                withNarrative: false,
            ));
        } catch (Throwable) {
            return null;
        }

        $max = max(3, (int) config('synthesis.context.max_lines', 6));
        $lines = ['Current synthesis (indexed facts; prefer tools for detail):'];

        if ($result->summary) {
            $lines[] = mb_substr($result->summary, 0, 180);
        }

        foreach (array_slice($result->blockers, 0, 2) as $item) {
            $lines[] = 'Blocker: '.$item->title;
        }

        foreach (array_slice($result->waitingFor, 0, 2) as $item) {
            $lines[] = 'Waiting: '.$item->title;
        }

        foreach (array_slice($result->recentChanges, 0, 1) as $item) {
            $lines[] = 'Recent: '.$item->title;
        }

        return implode("\n", array_slice($lines, 0, $max));
    }

    private function compute(SynthesisScope $scope): SynthesisResult
    {
        $pack = $this->collector->collect($scope);
        $waiting = $this->dedupe->items($this->waiting->resolve($pack));
        $commitments = $this->dedupe->items($this->commitments->resolve($pack, $scope->commitmentMode));
        $blockers = $this->dedupe->items($this->attention->blockers($pack, $waiting));
        $openWork = $this->changes->openWork($pack);
        $upcoming = $this->changes->upcoming($pack);
        $recent = $this->changes->recent($pack);
        $attention = $this->dedupe->items($this->attention->attention($pack, $blockers, $waiting, $commitments));
        $people = $this->peopleItems($pack);
        $openLoops = $this->openLoops($waiting, $commitments, $pack);
        $conflicts = $this->conflicts->detect($pack);
        $limits = config('synthesis.factpack', []);

        $result = new SynthesisResult(
            type: $scope->type,
            generatedAt: $pack->now,
            timezone: $pack->timezone,
            summary: $this->deterministicSummary($scope, $pack, $blockers, $waiting, $attention),
            narrativeUsed: false,
            blockers: $this->ranker->sort($blockers, (int) ($limits['max_waiting'] ?? 20)),
            waitingFor: $this->ranker->sort($waiting, (int) ($limits['max_waiting'] ?? 20)),
            commitments: $this->ranker->sort($commitments, (int) ($limits['max_commitments'] ?? 20)),
            openWork: $this->ranker->sort($openWork, (int) ($limits['max_tasks'] ?? 30)),
            recentChanges: $this->ranker->sort($recent, (int) ($limits['max_changes'] ?? 30)),
            people: $this->ranker->sort($people, (int) ($limits['max_people'] ?? 10)),
            upcoming: $this->ranker->sort($upcoming, (int) ($limits['max_reminders'] ?? 20)),
            attention: $this->ranker->sort($attention, (int) ($limits['max_attention'] ?? 12)),
            openLoops: $this->ranker->sort($openLoops, (int) ($limits['max_waiting'] ?? 20)),
            conflicts: $conflicts,
            sources: $this->collectSources($blockers, $waiting, $commitments, $recent, $openWork),
            project: $this->projectPayload($pack, $blockers, $waiting, $attention),
            person: $this->personPayload($pack, $waiting, $commitments, $recent),
            freshness: $pack->freshness,
        );

        if ($scope->withNarrative && $this->narrative !== null && $this->wantsNarrative($scope->type)) {
            $story = $this->narrative->summarize($scope->user, $pack, $result);

            if (is_string($story) && trim($story) !== '') {
                $result->summary = trim($story);
                $result->narrativeUsed = true;
            }
        }

        return $result;
    }

    /**
     * @param  list<SynthesisItem>  $blockers
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $attention
     */
    private function deterministicSummary(
        SynthesisScope $scope,
        FactPack $pack,
        array $blockers,
        array $waiting,
        array $attention,
    ): string {
        $bits = [];

        if ($pack->project !== null) {
            $bits[] = $pack->project->name;
        } elseif ($pack->entity !== null) {
            $bits[] = $pack->entity->name;
        }

        if ($blockers !== []) {
            $bits[] = 'blockers: '.$blockers[0]->title;
        }

        if ($waiting !== []) {
            $bits[] = 'waiting: '.$waiting[0]->title;
        }

        if ($attention !== [] && $blockers === []) {
            $bits[] = 'attention: '.$attention[0]->title;
        }

        if ($bits === []) {
            return match ($scope->type) {
                SynthesisType::WaitingFor => 'No open waiting-for items.',
                SynthesisType::Commitments => 'No explicit open commitments.',
                SynthesisType::Blockers => 'No grounded blockers.',
                SynthesisType::AttentionNeeded => 'Nothing currently needs attention.',
                default => 'No notable indexed changes in this window.',
            };
        }

        return implode(' · ', $bits);
    }

    /**
     * @return list<SynthesisItem>
     */
    private function peopleItems(FactPack $pack): array
    {
        $items = [];

        foreach ($pack->people as $person) {
            if (! $person instanceof KnowledgeEntity) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'person',
                title: $person->name,
                why: $person->summary,
                sources: [new SourceRef(entityId: (int) $person->id, projectId: $person->project_id, domain: 'knowledge')],
                extra: [
                    'role' => $person->metadata['role'] ?? null,
                    'org' => $person->metadata['org'] ?? null,
                ],
                fingerprint: 'person:'.$person->id,
            );
        }

        return $items;
    }

    /**
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $commitments
     * @return list<SynthesisItem>
     */
    private function openLoops(array $waiting, array $commitments, FactPack $pack): array
    {
        $items = array_merge($waiting, $commitments);
        $stale = $this->waiting->staleWatcherIds($pack->watchers);

        foreach ($pack->watchers as $watcher) {
            if (isset($stale[(int) $watcher->id])) {
                continue;
            }

            if ($watcher->mode->value === 'one_shot' && $watcher->status->value === 'active') {
                $items[] = new SynthesisItem(
                    kind: 'open_loop',
                    title: $watcher->name,
                    why: 'One-shot watcher still waiting.',
                    sources: [new SourceRef(watcherId: (int) $watcher->id, domain: 'watcher')],
                    fingerprint: 'loop:watcher:'.$watcher->id,
                );
            }
        }

        return $this->dedupe->items($items);
    }

    /**
     * @param  list<SynthesisItem>  $blockers
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $attention
     * @return array<string, mixed>
     */
    private function projectPayload(FactPack $pack, array $blockers, array $waiting, array $attention): array
    {
        if ($pack->project === null && $pack->entity?->type !== KnowledgeEntityType::Project) {
            return [];
        }

        $labels = $this->attention->projectLabels($pack, $blockers, $waiting, $attention);

        return array_filter([
            'id' => $pack->project?->id ?? $pack->entity?->project_id,
            'entity_id' => $pack->entity?->id,
            'name' => $pack->project?->name ?? $pack->entity?->name,
            'status' => $pack->project?->status->value ?? $pack->entity?->status->value,
            'labels' => $labels,
            'archived' => $pack->project?->status === ProjectStatus::Archived,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $commitments
     * @param  list<SynthesisItem>  $recent
     * @return array<string, mixed>
     */
    private function personPayload(FactPack $pack, array $waiting, array $commitments, array $recent): array
    {
        $entity = $pack->entity;

        if ($entity === null || $entity->type !== KnowledgeEntityType::Person) {
            return [];
        }

        $last = $this->lastInteraction($pack);

        return [
            'id' => $entity->id,
            'name' => $entity->name,
            'summary' => $entity->summary,
            'role' => $entity->metadata['role'] ?? null,
            'org' => $entity->metadata['org'] ?? null,
            'last_activity' => $last,
            'waiting_count' => count($waiting),
            'commitment_count' => count($commitments),
            'recent_count' => count($recent),
        ];
    }

    /**
     * @return array{at: ?string, kind: string}|array{at: null, kind: string}
     */
    private function lastInteraction(FactPack $pack): array
    {
        $interactionTypes = [
            KnowledgeEventType::EmailReceived->value,
            KnowledgeEventType::CalendarEvent->value,
            KnowledgeEventType::ConversationMentioned->value,
        ];

        foreach ($pack->events as $event) {
            if (in_array($event->type->value, $interactionTypes, true) && $event->occurred_at) {
                return [
                    'at' => $event->occurred_at->toIso8601String(),
                    'kind' => $event->type->value,
                    'title' => $event->title,
                ];
            }
        }

        return ['at' => null, 'kind' => 'unknown'];
    }

    /**
     * @param  list<SynthesisItem>  ...$groups
     * @return list<array<string, mixed>>
     */
    private function collectSources(array ...$groups): array
    {
        $out = [];
        $seen = [];

        foreach ($groups as $group) {
            foreach ($group as $item) {
                foreach ($item->sources as $source) {
                    $row = $source->toArray();
                    $key = md5((string) json_encode($row));

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $out[] = $row;
                }
            }
        }

        return array_slice($out, 0, 40);
    }

    private function wantsNarrative(SynthesisType $type): bool
    {
        return in_array($type, [
            SynthesisType::ProjectStatus,
            SynthesisType::PersonStatus,
            SynthesisType::DailyDigest,
            SynthesisType::WeeklyDigest,
        ], true);
    }
}
