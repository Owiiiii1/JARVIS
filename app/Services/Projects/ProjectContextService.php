<?php

namespace App\Services\Projects;

use App\Enums\ConversationSummaryStatus;
use App\Enums\MemoryStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\Project;
use App\Models\TelegramGroup;
use App\Models\Topic;
use App\Models\User;
use App\Services\Memory\MemoryKeyNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ProjectContextService
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, Project $project, ?string $query = null): array
    {
        $this->projects->assertOwns($user, $project);

        $query = trim((string) $query);
        $tokens = MemoryKeyNormalizer::tokens($query);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
            ],
            'topics' => $this->topics($user, $project, $tokens),
            'memories' => $this->memories($user, $project, $tokens),
            'conversation_summaries' => $this->summaries($user, $project, $tokens),
            'groups' => $this->groups($project),
        ];
    }

    /**
     * @return list<Project>
     */
    public function resolve(User $user, string $name): array
    {
        $this->projects->assertCanManage($user);

        $normalized = ProjectNameNormalizer::normalize($name);
        $limit = (int) config('projects.max_projects_search');

        if ($normalized === '') {
            return [];
        }

        $exact = Project::query()
            ->where('user_id', $user->id)
            ->where('status', ProjectStatus::Active)
            ->where('normalized_name', $normalized)
            ->limit($limit)
            ->get();

        if ($exact->isNotEmpty()) {
            return $exact->all();
        }

        $like = '%'.MemoryKeyNormalizer::escapeLike($normalized).'%';

        return Project::query()
            ->where('user_id', $user->id)
            ->where('status', ProjectStatus::Active)
            ->where(function (Builder $builder) use ($like): void {
                $builder->where('normalized_name', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{id: int, name: string, description: string|null}>
     */
    private function topics(User $user, Project $project, array $tokens): array
    {
        $max = (int) config('projects.max_topics');

        $topics = Topic::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $project->topics()->select('topics.id'))
            ->orderBy('name')
            ->limit(max($max * 3, $max))
            ->get();

        $ranked = $this->rank($topics, $tokens, static fn (Topic $topic): string => $topic->name.' '.$topic->normalized_name.' '.$topic->description);

        return $ranked->take($max)->map(static fn (Topic $topic): array => [
            'id' => $topic->id,
            'name' => $topic->name,
            'description' => $topic->description,
        ])->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{id: int, kind: string, content: string, confidence: float}>
     */
    private function memories(User $user, Project $project, array $tokens): array
    {
        $max = (int) config('projects.max_memories');

        $memories = Memory::query()
            ->where('user_id', $user->id)
            ->where('status', MemoryStatus::Active)
            ->whereIn('id', $project->memories()->select('memories.id'))
            ->where(function (Builder $builder): void {
                $builder->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->orderByDesc('confidence')
            ->limit(max($max * 3, $max))
            ->get();

        $ranked = $this->rank($memories, $tokens, static fn (Memory $memory): string => $memory->content.' '.$memory->normalized_key);

        return $ranked->take($max)->map(static fn (Memory $memory): array => [
            'id' => $memory->id,
            'kind' => $memory->kind->value,
            'content' => $memory->content,
            'confidence' => $memory->confidence,
        ])->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{conversation_id: int, title: string, summary: string|null, last_activity_at: string|null}>
     */
    private function summaries(User $user, Project $project, array $tokens): array
    {
        $max = (int) config('projects.max_summaries');

        $conversations = Conversation::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $project->conversations()->select('conversations.id'))
            ->orderByDesc('last_activity_at')
            ->limit(max($max * 3, $max))
            ->get();

        $summaryByConversation = ConversationSummary::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->where('status', ConversationSummaryStatus::Current)
            ->orderByDesc('version')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $rows = $conversations->map(function (Conversation $conversation) use ($summaryByConversation): array {
            $summary = $summaryByConversation->get($conversation->id);

            return [
                'conversation_id' => (int) $conversation->id,
                'title' => (string) $conversation->title,
                'summary' => $summary?->summary,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
            ];
        });

        $ranked = $rows
            ->sortByDesc(function (array $row) use ($tokens): float {
                $haystack = mb_strtolower($row['title'].' '.($row['summary'] ?? ''));
                $hits = 0;

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $hits++;
                    }
                }

                $tokenScore = $tokens === [] ? 0.4 : ($hits / max(1, count($tokens)));

                return $tokenScore;
            })
            ->values()
            ->take($max);

        return $ranked->all();
    }

    /**
     * @return list<array{id: int, title: string|null, status: string, chat_type: string}>
     */
    private function groups(Project $project): array
    {
        return $project->telegramGroups()
            ->orderBy('title')
            ->limit(20)
            ->get(['telegram_groups.id', 'telegram_groups.title', 'telegram_groups.status', 'telegram_groups.chat_type'])
            ->map(static fn (TelegramGroup $group): array => [
                'id' => (int) $group->id,
                'title' => $group->title,
                'status' => $group->status->value,
                'chat_type' => $group->chat_type,
            ])
            ->all();
    }

    /**
     * @template T
     * @param  Collection<int, T>  $rows
     * @param  list<string>  $tokens
     * @param  callable(T): string  $haystack
     * @return Collection<int, T>
     */
    private function rank(Collection $rows, array $tokens, callable $haystack): Collection
    {
        if ($tokens === []) {
            return $rows->values();
        }

        return $rows
            ->sortByDesc(function ($row) use ($tokens, $haystack): float {
                $text = mb_strtolower($haystack($row));
                $hits = 0;

                foreach ($tokens as $token) {
                    if (str_contains($text, $token)) {
                        $hits++;
                    }
                }

                return $hits / max(1, count($tokens));
            })
            ->values();
    }
}
