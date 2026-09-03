<?php

namespace App\Services\Memory;

use App\Enums\ConversationKind;
use App\Enums\ConversationSummaryStatus;
use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Memory\DTO\MemoryContextPackage;
use Illuminate\Database\Eloquent\Builder;

final class PersonalMemoryRetriever
{
    public function retrieve(User $user, Conversation $conversation, ?string $query = null): MemoryContextPackage
    {
        $query = trim((string) $query);

        return new MemoryContextPackage(
            memories: $this->memories($user, $query),
            crossChatSummaries: $this->crossChatSummaries($user, $conversation, $query),
            currentSummary: $this->currentSummary($user, $conversation),
            profile: $this->profile($user),
        );
    }

    /**
     * @return list<Memory>
     */
    private function memories(User $user, string $query): array
    {
        $minConfidence = (float) config('memory.retrieval.min_confidence');
        $max = (int) config('memory.retrieval.max_memories');
        $fallback = (int) config('memory.retrieval.fallback_memories');
        $candidateLimit = (int) config('memory.retrieval.candidate_limit');
        $tokens = MemoryKeyNormalizer::tokens($query);

        $base = Memory::query()
            ->where('user_id', $user->id)
            ->where('scope', MemoryScope::Personal)
            ->where('status', MemoryStatus::Active)
            ->where('confidence', '>=', $minConfidence)
            ->where(function (Builder $builder): void {
                $builder->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            });

        $matched = clone $base;

        if ($tokens !== []) {
            $matched->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.MemoryKeyNormalizer::escapeLike($token).'%';
                    $builder->orWhere('content', 'like', $like)
                        ->orWhere('normalized_key', 'like', $like);
                }
            });
        }

        $rows = $matched
            ->orderByDesc('confidence')
            ->orderByDesc('last_confirmed_at')
            ->limit($candidateLimit)
            ->get();

        if ($rows->isEmpty() && $tokens !== []) {
            $rows = $base
                ->orderByDesc('confidence')
                ->orderByDesc('last_confirmed_at')
                ->limit(max(1, $fallback))
                ->get();
        }

        $ranked = $rows
            ->sortByDesc(fn (Memory $memory): float => $this->score($memory->content.' '.$memory->normalized_key, $tokens, (float) $memory->confidence, $memory->last_confirmed_at?->getTimestamp() ?? 0))
            ->values()
            ->take($max);

        return $ranked->all();
    }

    /**
     * @return list<ConversationSummary>
     */
    private function crossChatSummaries(User $user, Conversation $conversation, string $query): array
    {
        $max = (int) config('memory.retrieval.max_cross_chat_summaries');
        $candidateLimit = (int) config('memory.retrieval.candidate_limit');
        $tokens = MemoryKeyNormalizer::tokens($query);

        $base = ConversationSummary::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', '!=', $conversation->id)
            ->where('status', ConversationSummaryStatus::Current)
            ->whereHas('conversation', static function (Builder $builder): void {
                $builder->where('kind', ConversationKind::Personal);
            })
            ->with('conversation:id,title,last_activity_at');

        $matched = clone $base;

        if ($tokens !== []) {
            $matched->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.MemoryKeyNormalizer::escapeLike($token).'%';
                    $builder->orWhere('summary', 'like', $like);
                }
            });
        }

        $rows = $matched
            ->orderByDesc('generated_at')
            ->limit($candidateLimit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows
            ->sortByDesc(fn (ConversationSummary $summary): float => $this->score(
                $summary->summary.' '.($summary->conversation?->title ?? ''),
                $tokens,
                0.7,
                $summary->generated_at?->getTimestamp() ?? 0,
            ))
            ->values()
            ->take($max)
            ->all();
    }

    private function currentSummary(User $user, Conversation $conversation): ?ConversationSummary
    {
        if ($conversation->kind !== ConversationKind::Personal) {
            return null;
        }
        return ConversationSummary::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', ConversationSummaryStatus::Current)
            ->orderByDesc('version')
            ->first();
    }

    private function profile(User $user): ?UserProfile
    {
        return UserProfile::query()->where('user_id', $user->id)->first();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function score(string $haystack, array $tokens, float $confidence, int $freshness): float
    {
        $haystack = mb_strtolower($haystack);
        $hits = 0;

        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $hits++;
            }
        }

        $tokenScore = $tokens === [] ? 0.4 : ($hits / count($tokens));

        return ($tokenScore * 4) + ($confidence * 2) + min(1, $freshness / 2_000_000_000);
    }
}
