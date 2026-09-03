<?php

namespace App\Services\Memory;

use App\Enums\ConversationKind;
use App\Enums\ConversationSummaryStatus;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ConversationHistorySearch
{
    /**
     * @return list<array{conversation_id: int, conversation_title: string, message_id: int, occurred_at: string|null, snippet: string}>
     */
    public function search(User $user, string $query, ?string $conversationHint = null, ?int $limit = null): array
    {
        $max = min(
            (int) config('memory.search.max_snippets'),
            max(1, $limit ?? (int) config('memory.search.max_snippets')),
        );
        $snippetChars = (int) config('memory.search.max_snippet_chars');
        $candidateLimit = (int) config('memory.search.candidate_limit');
        $tokens = MemoryKeyNormalizer::tokens($query, 2);

        if ($tokens === []) {
            return [];
        }

        $conversationIds = $this->conversationIds($user, $conversationHint, $tokens);

        if ($conversationIds->isEmpty()) {
            return [];
        }

        $messages = Message::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', $conversationIds->all())
            ->whereIn('role', [MessageRole::User, MessageRole::Assistant])
            ->where('message_type', '!=', MessageType::System)
            ->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->orWhere('body', 'like', '%'.MemoryKeyNormalizer::escapeLike($token).'%');
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->with('conversation:id,title,user_id')
            ->get();

        $ranked = $messages
            ->filter(fn (Message $message): bool => (int) $message->user_id === (int) $user->id
                && (int) $message->conversation?->user_id === (int) $user->id)
            ->sortByDesc(function (Message $message) use ($tokens): float {
                $haystack = mb_strtolower((string) $message->body.' '.$message->conversation?->title);
                $hits = 0;

                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $hits++;
                    }
                }

                return $hits;
            })
            ->values()
            ->take($max);

        return $ranked->map(function (Message $message) use ($snippetChars): array {
            $body = trim((string) $message->body);

            if (mb_strlen($body) > $snippetChars) {
                $body = mb_substr($body, 0, $snippetChars).'…';
            }

            return [
                'conversation_id' => (int) $message->conversation_id,
                'conversation_title' => (string) ($message->conversation?->title ?? ''),
                'message_id' => (int) $message->id,
                'occurred_at' => optional($message->occurred_at)?->toIso8601String(),
                'snippet' => $body,
            ];
        })->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return Collection<int, int>
     */
    private function conversationIds(User $user, ?string $conversationHint, array $tokens): Collection
    {
        $conversations = Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->orderByDesc('last_activity_at')
            ->limit(50);

        $hint = trim((string) $conversationHint);

        if ($hint !== '') {
            $conversations->where(function (Builder $builder) use ($hint): void {
                $like = '%'.MemoryKeyNormalizer::escapeLike($hint).'%';
                $builder->where('title', 'like', $like);
            });
        }

        $ids = $conversations->pluck('id');

        $summaryIds = ConversationSummary::query()
            ->where('user_id', $user->id)
            ->where('status', ConversationSummaryStatus::Current)
            ->where(function (Builder $builder) use ($tokens, $hint): void {
                foreach ($tokens as $token) {
                    $builder->orWhere('summary', 'like', '%'.MemoryKeyNormalizer::escapeLike($token).'%');
                }

                if ($hint !== '') {
                    $builder->orWhere('summary', 'like', '%'.MemoryKeyNormalizer::escapeLike($hint).'%');
                }
            })
            ->limit(20)
            ->pluck('conversation_id');

        $topicConversationIds = Topic::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $builder) use ($tokens, $hint): void {
                foreach ($tokens as $token) {
                    $like = '%'.MemoryKeyNormalizer::escapeLike($token).'%';
                    $builder->orWhere('name', 'like', $like)
                        ->orWhere('normalized_name', 'like', $like);
                }

                if ($hint !== '') {
                    $like = '%'.MemoryKeyNormalizer::escapeLike($hint).'%';
                    $builder->orWhere('name', 'like', $like);
                }
            })
            ->limit((int) config('memory.retrieval.max_topics'))
            ->pluck('id');

        $fromTopics = collect();

        if ($topicConversationIds->isNotEmpty()) {
            $fromTopics = Message::query()
                ->where('user_id', $user->id)
                ->whereIn('id', function ($query) use ($topicConversationIds): void {
                    $query->select('message_id')
                        ->from('message_topic_relations')
                        ->whereIn('topic_id', $topicConversationIds);
                })
                ->limit(40)
                ->pluck('conversation_id');
        }

        return $ids->merge($summaryIds)->merge($fromTopics)->unique()->values();
    }
}
