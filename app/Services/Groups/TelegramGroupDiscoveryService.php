<?php

namespace App\Services\Groups;

use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Enums\TelegramGroupStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Groups\Exceptions\TelegramGroupException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TelegramGroupDiscoveryService
{
    /**
     * @param  array{
     *     title?: string|null,
     *     username?: string|null,
     *     chat_type: string,
     *     is_forum?: bool,
     *     metadata?: array<string, mixed>|null
     * }  $attributes
     */
    public function discoverOrCreate(string $telegramChatId, array $attributes): TelegramGroup
    {
        $existing = TelegramGroup::query()->where('telegram_chat_id', $telegramChatId)->first();

        if ($existing !== null) {
            $this->refresh($existing, $attributes);

            return $existing;
        }

        $owner = $this->owner();

        try {
            return DB::transaction(function () use ($telegramChatId, $attributes, $owner): TelegramGroup {
                $locked = TelegramGroup::query()
                    ->where('telegram_chat_id', $telegramChatId)
                    ->lockForUpdate()
                    ->first();

                if ($locked !== null) {
                    $this->refresh($locked, $attributes);

                    return $locked;
                }

                $title = $this->normalizedTitle($attributes['title'] ?? null);
                $now = now();

                $conversation = Conversation::query()->create([
                    'user_id' => $owner->id,
                    'kind' => ConversationKind::Group,
                    'title' => $title,
                    'status' => ConversationStatus::Active,
                    'last_activity_at' => $now,
                ]);

                $metadata = $attributes['metadata'] ?? [];
                if (($attributes['is_forum'] ?? false) === true) {
                    $metadata['is_forum'] = true;
                }

                return TelegramGroup::query()->create([
                    'telegram_chat_id' => $telegramChatId,
                    'conversation_id' => $conversation->id,
                    'title' => $attributes['title'] ?? null,
                    'username' => $this->nullableString($attributes['username'] ?? null),
                    'chat_type' => $attributes['chat_type'],
                    'status' => TelegramGroupStatus::Connected,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'settings' => ['mode' => TelegramGroup::MODE_PERSIST_ONLY],
                    'metadata' => $metadata === [] ? null : $metadata,
                    'message_count' => 0,
                ]);
            });
        } catch (QueryException $exception) {
            $recovered = TelegramGroup::query()->where('telegram_chat_id', $telegramChatId)->first();

            if ($recovered !== null) {
                $this->refresh($recovered, $attributes);

                return $recovered;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function refresh(TelegramGroup $group, array $attributes): void
    {
        $updates = [
            'last_seen_at' => now(),
        ];

        if (array_key_exists('title', $attributes) && filled($attributes['title'])) {
            $title = (string) $attributes['title'];
            $updates['title'] = $title;

            if ($group->conversation !== null && $group->conversation->title !== $this->normalizedTitle($title)) {
                $group->conversation->forceFill([
                    'title' => $this->normalizedTitle($title),
                ])->save();
            } elseif ($group->conversation_id) {
                Conversation::query()->whereKey($group->conversation_id)->update([
                    'title' => $this->normalizedTitle($title),
                ]);
            }
        }

        if (array_key_exists('username', $attributes)) {
            $updates['username'] = $this->nullableString($attributes['username'] ?? null);
        }

        if (filled($attributes['chat_type'] ?? null)) {
            $updates['chat_type'] = (string) $attributes['chat_type'];
        }

        $metadata = $group->metadata ?? [];
        if (($attributes['is_forum'] ?? false) === true) {
            $metadata['is_forum'] = true;
            $updates['metadata'] = $metadata;
        }

        $group->forceFill($updates)->save();
    }

    public function owner(): User
    {
        $owner = User::query()->where('role', UserRole::Owner)->orderBy('id')->first();

        if ($owner === null) {
            throw new TelegramGroupException('owner_missing', 'Owner account is missing.');
        }

        return $owner;
    }

    private function normalizedTitle(?string $title): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return 'Telegram group';
        }

        return mb_substr($title, 0, 120);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
