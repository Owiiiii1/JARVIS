<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatAttachments\ChatAttachmentPresenter;
use App\Services\Storage\StoredFileService;

final class MessageHistoryService
{
    public const PAGE_SIZE = 80;

    public function __construct(
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly ChatAttachmentPresenter $attachments,
        private readonly StoredFileService $storedFiles,
    ) {}

    /**
     * @return array{messages: list<array<string, mixed>>, has_more: bool, oldest_id: int|null}
     */
    public function page(Conversation $conversation, ?int $beforeId = null, int $limit = self::PAGE_SIZE): array
    {
        $limit = max(1, min(100, $limit));

        $query = Message::query()
            ->with(['attachments', 'storedFiles'])
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->limit($limit * 3)->get();
        $visible = [];

        foreach ($rows as $message) {
            if (! $this->isCabinetVisible($message)) {
                continue;
            }

            $visible[] = $message;

            if (count($visible) >= $limit + 1) {
                break;
            }
        }

        $hasMore = count($visible) > $limit;
        $page = array_slice($visible, 0, $limit);
        $page = array_reverse($page);

        return [
            'messages' => array_map(fn (Message $message): array => $this->toArray($message), $page),
            'has_more' => $hasMore,
            'oldest_id' => $page === [] ? null : (int) $page[0]->id,
        ];
    }

    public function isCabinetVisible(Message $message): bool
    {
        if ($this->contextBuilder->isSemanticDialogue($message)) {
            return true;
        }

        if ($message->role !== MessageRole::System) {
            return false;
        }

        $body = (string) $message->body;

        if (str_contains($body, 'Сообщение сохранено')) {
            return false;
        }

        return ($message->metadata['technical'] ?? false) === true
            || $body === ConversationAiService::AI_FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Message $message): array
    {
        $kind = match ($message->role) {
            MessageRole::Assistant => 'assistant',
            MessageRole::System => 'error',
            default => 'user',
        };

        $pending = $message->metadata['pending_confirmation'] ?? null;

        return [
            'id' => $message->id,
            'kind' => $kind,
            'role' => $message->role->value,
            'channel' => $message->channel->value,
            'body' => $message->body,
            'occurred_at' => optional($message->occurred_at)?->toIso8601String(),
            'pending_confirmation' => is_array($pending) && filled($pending['id'] ?? null)
                ? [
                    'id' => (string) $pending['id'],
                    'tool_name' => (string) ($pending['tool_name'] ?? ''),
                    'summary' => (string) ($pending['summary'] ?? ''),
                    'preview' => is_array($pending['preview'] ?? null) ? $pending['preview'] : null,
                    'expires_at' => filled($pending['expires_at'] ?? null)
                        ? (string) $pending['expires_at']
                        : null,
                ]
                : null,
            'attachments' => $this->attachments->forMessage($message),
            'stored_files' => $this->storedFiles->cardsForMessage($message),
            'status' => 'completed',
        ];
    }
}
