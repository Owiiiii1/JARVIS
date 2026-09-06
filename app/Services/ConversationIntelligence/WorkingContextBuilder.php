<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\MessageRole;
use App\Enums\ReferenceOutcome;
use App\Enums\ToolOperationClass;
use App\Enums\TopicContinuityMode;
use App\Enums\TopicStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Topic;
use App\Models\User;
use App\Services\Memory\DTO\MemoryContextPackage;
use Throwable;

final class WorkingContextBuilder
{
    public function __construct(
        private readonly TopicContinuityDetector $topics,
        private readonly ReferenceResolver $references,
        private readonly ClarificationPolicy $clarifications,
        private readonly RecentToolReferenceReader $toolReferences,
        private readonly TemporaryStyleExtractor $styles,
    ) {}

    public function buildSafe(
        User $user,
        Conversation $conversation,
        ?Message $currentInbound = null,
        ?MemoryContextPackage $memory = null,
        array $recentSemanticTexts = [],
    ): WorkingContext {
        try {
            return $this->build($user, $conversation, $currentInbound, $memory, $recentSemanticTexts);
        } catch (Throwable) {
            return WorkingContext::unavailable();
        }
    }

    /**
     * @param  list<string>  $recentSemanticTexts  newest last
     */
    public function build(
        User $user,
        Conversation $conversation,
        ?Message $currentInbound = null,
        ?MemoryContextPackage $memory = null,
        array $recentSemanticTexts = [],
    ): WorkingContext {
        $currentText = trim((string) ($currentInbound?->body ?? ''));
        $knownTopics = $this->topicNames($user, $conversation);
        $toolRefs = $this->toolReferences->forConversation($user, $conversation);
        $incomplete = $this->references->looksIncomplete($currentText);
        $currentTopic = $this->currentTopicName($knownTopics, $memory, $currentText);
        $previousTopic = $this->previousTopicName($knownTopics, $currentTopic);
        $mode = $this->topics->detect($currentText, $recentSemanticTexts, $knownTopics, $currentTopic);

        if ($mode === TopicContinuityMode::Return) {
            $returned = $this->topics->returnedTopicName($currentText, $knownTopics, $currentTopic);

            if ($returned !== null && $returned !== '') {
                $previousTopic = $currentTopic;
                $currentTopic = $returned;
            }
        }

        $entities = $this->mergeEntities(
            $toolRefs,
            $this->projectEntities($user, $currentText, $recentSemanticTexts),
            $this->topicEntities($knownTopics),
        );

        if ($mode === TopicContinuityMode::Switch) {
            $entities = array_map(
                static fn (ConversationalEntity $entity): ConversationalEntity => new ConversationalEntity(
                    type: $entity->type,
                    label: $entity->label,
                    id: $entity->id,
                    trusted: false,
                    expired: true,
                ),
                $entities,
            );
            $toolRefs = array_map(
                static fn (ConversationalEntity $entity): ConversationalEntity => new ConversationalEntity(
                    type: $entity->type,
                    label: $entity->label,
                    id: $entity->id,
                    trusted: false,
                    expired: true,
                ),
                $toolRefs,
            );
        }

        $lastImportant = $this->lastImportant($toolRefs, $entities);
        $resolved = $this->references->resolve($currentText, $entities, $lastImportant, $incomplete);

        if ($resolved['entity'] instanceof ConversationalEntity) {
            $lastImportant = $resolved['entity'];
        }

        $activeProject = $this->activeProjectLabel($entities, $currentText, $recentSemanticTexts);
        $working = new WorkingContext(
            topicMode: $mode,
            referenceOutcome: $resolved['outcome'],
            continuitySource: $this->continuitySource($mode, $memory, $knownTopics, $toolRefs),
            currentTopic: $currentTopic,
            previousTopic: $previousTopic,
            recentEntities: array_slice($entities, 0, 8),
            recentToolReferences: array_slice($toolRefs, 0, 6),
            recentIntent: $this->recentIntent($currentText, $mode),
            pendingClarification: null,
            lastImportantObject: $lastImportant,
            activeProject: $activeProject,
            temporaryStyle: $this->styles->extract($this->recentUserTexts($conversation, $currentInbound)),
            incompleteUtterance: $incomplete,
            clarificationReason: null,
            available: true,
        );

        $reason = $this->clarifications->reason($working, $this->mutationClass($currentText));
        $pending = $reason === null ? null : $this->pendingClarificationText($reason, $resolved['outcome']);

        return new WorkingContext(
            topicMode: $working->topicMode,
            referenceOutcome: $working->referenceOutcome,
            continuitySource: $working->continuitySource,
            currentTopic: $working->currentTopic,
            previousTopic: $working->previousTopic,
            recentEntities: $working->recentEntities,
            recentToolReferences: $working->recentToolReferences,
            recentIntent: $working->recentIntent,
            pendingClarification: $pending,
            lastImportantObject: $working->lastImportantObject,
            activeProject: $working->activeProject,
            temporaryStyle: $working->temporaryStyle,
            incompleteUtterance: $working->incompleteUtterance,
            clarificationReason: $reason,
            available: true,
        );
    }

    /**
     * @return list<string>
     */
    private function topicNames(User $user, Conversation $conversation): array
    {
        return Topic::query()
            ->where('user_id', $user->id)
            ->where('status', TopicStatus::Active)
            ->whereHas('messages', static function ($query) use ($conversation): void {
                $query->where('conversation_id', $conversation->id);
            })
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->pluck('name')
            ->filter()
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->reject(static fn (string $name): bool => $name === '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $knownTopics
     */
    private function currentTopicName(array $knownTopics, ?MemoryContextPackage $memory, string $currentText): ?string
    {
        foreach ($knownTopics as $topic) {
            if ($topic !== '' && $currentText !== '' && mb_stripos($currentText, $topic) !== false) {
                return $topic;
            }
        }

        if ($knownTopics !== []) {
            return $knownTopics[0];
        }

        $summary = trim((string) ($memory?->currentSummary?->summary ?? ''));

        if ($summary !== '') {
            return mb_substr(preg_replace('/\s+/u', ' ', $summary) ?? $summary, 0, 80);
        }

        return null;
    }

    /**
     * @param  list<string>  $knownTopics
     */
    private function previousTopicName(array $knownTopics, ?string $currentTopic): ?string
    {
        foreach ($knownTopics as $topic) {
            if ($topic !== '' && $topic !== $currentTopic) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @param  list<ConversationalEntity>  ...$groups
     * @return list<ConversationalEntity>
     */
    private function mergeEntities(array ...$groups): array
    {
        $merged = [];
        $seen = [];

        foreach ($groups as $group) {
            foreach ($group as $entity) {
                $key = $entity->type.':'.(string) ($entity->id ?? $entity->label);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $merged[] = $entity;
            }
        }

        return $merged;
    }

    /**
     * @param  list<string>  $recentSemanticTexts
     * @return list<ConversationalEntity>
     */
    private function projectEntities(User $user, string $currentText, array $recentSemanticTexts): array
    {
        $haystack = $this->normalize($currentText.' '.implode(' ', array_slice($recentSemanticTexts, -6)));

        if ($haystack === '') {
            return [];
        }

        $projects = Project::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'name']);

        $entities = [];

        foreach ($projects as $project) {
            $name = trim((string) $project->name);

            if ($name === '' || ! str_contains($haystack, $this->normalize($name))) {
                continue;
            }

            $entities[] = new ConversationalEntity(
                type: 'project',
                label: $name,
                id: (int) $project->id,
                trusted: true,
            );
        }

        return $entities;
    }

    /**
     * @param  list<string>  $knownTopics
     * @return list<ConversationalEntity>
     */
    private function topicEntities(array $knownTopics): array
    {
        $entities = [];

        foreach (array_slice($knownTopics, 0, 5) as $topic) {
            $entities[] = new ConversationalEntity(type: 'topic', label: $topic);
        }

        return $entities;
    }

    /**
     * @param  list<ConversationalEntity>  $toolRefs
     * @param  list<ConversationalEntity>  $entities
     */
    private function lastImportant(array $toolRefs, array $entities): ?ConversationalEntity
    {
        foreach ($toolRefs as $entity) {
            if (! $entity->expired && $entity->trusted) {
                return $entity;
            }
        }

        foreach ($entities as $entity) {
            if (! $entity->expired && in_array($entity->type, ['task', 'reminder', 'project', 'calendar_event', 'file'], true)) {
                return $entity;
            }
        }

        return $entities[0] ?? null;
    }

    /**
     * @param  list<ConversationalEntity>  $entities
     * @param  list<string>  $recentSemanticTexts
     */
    private function activeProjectLabel(array $entities, string $currentText, array $recentSemanticTexts): ?string
    {
        foreach ($entities as $entity) {
            if ($entity->type === 'project' && ! $entity->expired) {
                return $entity->label;
            }
        }

        $haystack = $this->normalize($currentText.' '.implode(' ', array_slice($recentSemanticTexts, -4)));

        if (preg_match('/\b(yfs)\b/u', $haystack, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $knownTopics
     * @param  list<ConversationalEntity>  $toolRefs
     */
    private function continuitySource(
        TopicContinuityMode $mode,
        ?MemoryContextPackage $memory,
        array $knownTopics,
        array $toolRefs,
    ): string {
        if ($mode === TopicContinuityMode::Return) {
            return $knownTopics !== [] ? 'topics' : 'summary';
        }

        if ($toolRefs !== []) {
            return 'recent_tool_results';
        }

        if ($memory?->currentSummary !== null) {
            return 'conversation_summary';
        }

        return 'recent_tail';
    }

    private function recentIntent(string $currentText, TopicContinuityMode $mode): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $currentText) ?? $currentText);

        if ($text === '') {
            return null;
        }

        return $mode->value.': '.mb_substr($text, 0, 140);
    }

    /**
     * @return list<string>
     */
    private function recentUserTexts(Conversation $conversation, ?Message $currentInbound): array
    {
        $texts = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::User)
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('body')
            ->map(static fn (mixed $body): string => trim((string) $body))
            ->reject(static fn (string $body): bool => $body === '')
            ->reverse()
            ->values()
            ->all();

        $current = trim((string) ($currentInbound?->body ?? ''));

        if ($current !== '' && ($texts === [] || $texts[array_key_last($texts)] !== $current)) {
            $texts[] = $current;
        }

        return $texts;
    }

    private function mutationClass(string $currentText): ?ToolOperationClass
    {
        $normalized = $this->normalize($currentText);

        if (preg_match('/\b(удали|отмени|cancel|complete|закрой|закройте)\b/u', $normalized) === 1) {
            return ToolOperationClass::Destructive;
        }

        if (preg_match('/\b(напомни|создай|добавь|измени|переименуй|update|link|свяжи)\b/u', $normalized) === 1) {
            return ToolOperationClass::Write;
        }

        return null;
    }

    private function pendingClarificationText(string $reason, ReferenceOutcome $outcome): string
    {
        if ($reason === 'write_target_ambiguous' || $outcome === ReferenceOutcome::Ambiguous) {
            return 'Several matching write targets. Ask which one; do not guess.';
        }

        return 'Clarification needed: '.$reason;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
