<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\ToolConfirmationStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolConfirmation;
use App\Services\ChatAttachments\ChatAttachmentPresenter;
use App\Services\Storage\StoredFileService;
use App\Services\Tools\ToolConfirmationService;

final class MessageHistoryService
{
    public const PAGE_SIZE = 80;

    public function __construct(
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly ChatAttachmentPresenter $attachments,
        private readonly StoredFileService $storedFiles,
        private readonly ToolConfirmationService $confirmations,
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
        $rows = $this->confirmationRowsFor($conversation, $page);

        return [
            'messages' => array_map(fn (Message $message): array => $this->toArray($message, $rows), $page),
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
     * @param  array<string, ToolConfirmation>  $confirmationRows
     * @return array<string, mixed>
     */
    public function toArray(Message $message, array $confirmationRows = []): array
    {
        $kind = match ($message->role) {
            MessageRole::Assistant => 'assistant',
            MessageRole::System => 'error',
            default => 'user',
        };

        return [
            'id' => $message->id,
            'kind' => $kind,
            'role' => $message->role->value,
            'channel' => $message->channel->value,
            'body' => $message->body,
            'occurred_at' => optional($message->occurred_at)?->toIso8601String(),
            'pending_confirmation' => $this->confirmationFromSnapshot(
                is_array($message->metadata['pending_confirmation'] ?? null)
                    ? $message->metadata['pending_confirmation']
                    : null,
                $confirmationRows,
            ),
            'attachments' => $this->attachments->forMessage($message),
            'stored_files' => $this->storedFiles->cardsForMessage($message),
            'status' => 'completed',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @param  array<string, ToolConfirmation>  $rows
     * @return array{id: string, status: string, tool_name: string, summary: string, preview: array<string, mixed>|null, expires_at: string|null}|null
     */
    public function confirmationFromSnapshot(?array $snapshot, array $rows = []): ?array
    {
        if (! is_array($snapshot) || ! filled($snapshot['id'] ?? null)) {
            return null;
        }

        $publicId = (string) $snapshot['id'];
        $row = $rows[$publicId] ?? ToolConfirmation::query()->where('public_id', $publicId)->first();

        return $this->confirmationCard($publicId, $snapshot, $row);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{id: string, status: string, tool_name: string, summary: string, preview: array<string, mixed>|null, expires_at: string|null}
     */
    public function confirmationCard(string $publicId, ?array $snapshot, ?ToolConfirmation $row): array
    {
        $snapshot ??= [];

        return [
            'id' => $publicId,
            'status' => $this->visibleStatus($row),
            'tool_name' => (string) ($snapshot['tool_name'] ?? $row?->tool_name ?? ''),
            'summary' => (string) ($snapshot['summary'] ?? ''),
            'preview' => is_array($snapshot['preview'] ?? null) ? $snapshot['preview'] : null,
            'expires_at' => optional($row?->expires_at)?->toIso8601String()
                ?? (filled($snapshot['expires_at'] ?? null) ? (string) $snapshot['expires_at'] : null),
        ];
    }

    /**
     * @param  list<Message>  $messages
     * @return array<string, ToolConfirmation>
     */
    private function confirmationRowsFor(Conversation $conversation, array $messages): array
    {
        $conversation->loadMissing('user');

        if ($conversation->user !== null) {
            $this->confirmations->expireStale($conversation->user, $conversation);
        }

        $ids = [];

        foreach ($messages as $message) {
            $id = $message->metadata['pending_confirmation']['id'] ?? null;

            if (filled($id)) {
                $ids[] = (string) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return ToolConfirmation::query()
            ->whereIn('public_id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('public_id')
            ->all();
    }

    private function visibleStatus(?ToolConfirmation $row): string
    {
        if ($row === null) {
            return ToolConfirmationStatus::Expired->value;
        }

        if ($row->isExpired() || $row->status === ToolConfirmationStatus::Expired) {
            return ToolConfirmationStatus::Expired->value;
        }

        return $row->status->value;
    }
}
