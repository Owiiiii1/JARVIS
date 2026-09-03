<?php

namespace App\Services\Groups;

use App\Models\Message;
use App\Models\TelegramGroup;

final class TelegramGroupHistoryService
{
    public function page(TelegramGroup $group, ?int $beforeId = null, ?int $limit = null): array
    {
        $limit = max(1, min(100, $limit ?? (int) config('telegram_groups.history_page_size')));

        $query = Message::query()
            ->where('conversation_id', $group->conversation_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->reverse()->values();

        return [
            'messages' => $page->map(fn (Message $message): array => $this->toArray($message))->all(),
            'has_more' => $hasMore,
            'oldest_id' => $page->first()?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Message $message): array
    {
        $metadata = $message->metadata ?? [];

        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'kind' => $message->role->value === 'assistant' || ($metadata['group_outbound'] ?? false) === true
                ? 'bot'
                : 'user',
            'channel' => $message->channel->value,
            'body' => $message->body,
            'message_type' => $message->message_type->value,
            'sender_name' => $message->sender_name,
            'sender_username' => $message->sender_username,
            'reply_to_channel_message_id' => $message->reply_to_channel_message_id,
            'thread_id' => $message->thread_id,
            'edited_at' => optional($message->edited_at)?->toIso8601String(),
            'occurred_at' => optional($message->occurred_at)?->toIso8601String(),
            'group_outbound' => ($metadata['group_outbound'] ?? false) === true,
            'media' => $metadata['telegram']['file'] ?? null,
            'placeholder' => $message->message_type->value !== 'text',
        ];
    }
}
