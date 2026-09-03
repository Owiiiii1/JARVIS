<?php

namespace App\Services\Conversations;

use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ConversationService
{
    public const LIST_LIMIT = 20;

    public const CABINET_LIST_LIMIT = 50;

    public const TITLE_MAX_LENGTH = 120;

    public const NEW_CHAT_TITLE = 'Новый чат';

    public function createPersonal(User $user, string $title): Conversation
    {
        $normalizedTitle = $this->normalizeTitle($title);

        return Conversation::query()->create([
            'user_id' => $user->id,
            'kind' => ConversationKind::Personal,
            'title' => $normalizedTitle,
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function listForUser(User $user, int $limit = self::LIST_LIMIT): Collection
    {
        return Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findOwned(User $user, int $conversationId): ?Conversation
    {
        return Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($conversationId)
            ->first();
    }

    public function getOrCreateDefault(User $user): Conversation
    {
        $existing = Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->where('title', Conversation::DEFAULT_TITLE)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createPersonal($user, Conversation::DEFAULT_TITLE);
    }

    public function rename(User $user, Conversation $conversation, string $title): Conversation
    {
        $owned = $this->findOwned($user, (int) $conversation->id);

        if ($owned === null) {
            throw new InvalidArgumentException('Conversation is not owned by this user.');
        }

        $owned->forceFill([
            'title' => $this->normalizeTitle($title),
        ])->save();

        return $owned->fresh();
    }

    public function ensureOwned(User $user, int $conversationId): Conversation
    {
        $conversation = $this->findOwned($user, $conversationId);

        if ($conversation === null) {
            abort(404);
        }

        return $conversation;
    }

    public function ensureActiveConversation(ChannelIdentity $identity): Conversation
    {
        $identity->loadMissing('user');

        if ($identity->active_conversation_id !== null) {
            $active = $this->findOwned($identity->user, (int) $identity->active_conversation_id);

            if ($active !== null) {
                return $active;
            }
        }

        $conversation = $this->getOrCreateDefault($identity->user);
        $this->setActiveConversation($identity, $conversation);

        return $conversation;
    }

    public function setActiveConversation(ChannelIdentity $identity, Conversation $conversation): bool
    {
        if ((int) $identity->user_id !== (int) $conversation->user_id) {
            return false;
        }

        if ($identity->active_conversation_id !== $conversation->id) {
            $identity->forceFill([
                'active_conversation_id' => $conversation->id,
            ])->save();
        }

        $identity->setRelation('activeConversation', $conversation);

        return true;
    }

    public function normalizeTitle(string $title): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        if ($normalized === '') {
            throw new InvalidArgumentException('Conversation title is empty.');
        }

        if (mb_strlen($normalized) > self::TITLE_MAX_LENGTH) {
            throw new InvalidArgumentException('Conversation title is too long.');
        }

        return $normalized;
    }

    public function isValidTitle(string $title): bool
    {
        try {
            $this->normalizeTitle($title);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
