<?php

namespace App\Services\Groups;

use App\Enums\MessageRole;
use App\Enums\MessageType;
use DateTimeImmutable;
use SergiX44\Nutgram\Telegram\Properties\MessageType as TelegramMessageType;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class TelegramGroupMessageMapper
{
    /**
     * @return array{
     *     role: MessageRole,
     *     message_type: MessageType,
     *     body: string|null,
     *     channel_message_id: string,
     *     sender_external_id: string|null,
     *     sender_username: string|null,
     *     sender_name: string|null,
     *     reply_to_channel_message_id: string|null,
     *     thread_id: string|null,
     *     occurred_at: DateTimeImmutable,
     *     edited_at: DateTimeImmutable|null,
     *     metadata: array<string, mixed>,
     *     participant: array{
     *         telegram_user_id: string,
     *         username: string|null,
     *         first_name: string|null,
     *         last_name: string|null,
     *         display_name: string|null,
     *         is_bot: bool
     *     }|null
     * }
     */
    public function map(Message $message): array
    {
        $from = $message->from;
        $senderChat = $message->sender_chat ?? null;
        $telegramType = $message->getType();
        $mappedType = $this->mapType($telegramType);
        $body = $this->body($message, $mappedType);
        $metadata = $this->metadata($message, $mappedType, $telegramType);

        $participant = null;
        $senderExternalId = null;
        $senderUsername = null;
        $senderName = null;
        $role = MessageRole::User;

        if ($from !== null) {
            $senderExternalId = (string) $from->id;
            $senderUsername = $from->username;
            $senderName = $this->displayName($from->first_name, $from->last_name, $from->username);
            $role = $from->is_bot ? MessageRole::Assistant : MessageRole::User;
            $participant = [
                'telegram_user_id' => $senderExternalId,
                'username' => $from->username,
                'first_name' => $from->first_name,
                'last_name' => $from->last_name,
                'display_name' => $senderName,
                'is_bot' => (bool) $from->is_bot,
            ];
        } elseif ($senderChat !== null) {
            $senderExternalId = isset($senderChat->id) ? (string) $senderChat->id : null;
            $senderUsername = $senderChat->username ?? null;
            $senderName = $senderChat->title ?? $senderChat->username ?? 'Anonymous admin';
            $metadata['sender_chat'] = array_filter([
                'id_present' => $senderExternalId !== null,
                'title' => $senderChat->title ?? null,
                'username' => $senderChat->username ?? null,
                'type' => isset($senderChat->type) ? (is_string($senderChat->type) ? $senderChat->type : $senderChat->type->value) : null,
            ], static fn ($value) => $value !== null && $value !== false);
        }

        $occurredAt = (new DateTimeImmutable)->setTimestamp((int) ($message->date ?? time()));
        $editedAt = isset($message->edit_date) && $message->edit_date
            ? (new DateTimeImmutable)->setTimestamp((int) $message->edit_date)
            : null;

        return [
            'role' => $role,
            'message_type' => $mappedType,
            'body' => $body,
            'channel_message_id' => (string) $message->message_id,
            'sender_external_id' => $senderExternalId,
            'sender_username' => $senderUsername,
            'sender_name' => $senderName,
            'reply_to_channel_message_id' => isset($message->reply_to_message?->message_id)
                ? (string) $message->reply_to_message->message_id
                : null,
            'thread_id' => isset($message->message_thread_id) ? (string) $message->message_thread_id : null,
            'occurred_at' => $occurredAt,
            'edited_at' => $editedAt,
            'metadata' => $metadata,
            'participant' => $participant,
        ];
    }

    private function mapType(?TelegramMessageType $type): MessageType
    {
        return match ($type) {
            TelegramMessageType::TEXT => MessageType::Text,
            TelegramMessageType::PHOTO, TelegramMessageType::LIVE_PHOTO => MessageType::Photo,
            TelegramMessageType::DOCUMENT, TelegramMessageType::ANIMATION => MessageType::Document,
            TelegramMessageType::VIDEO, TelegramMessageType::VIDEO_NOTE => MessageType::Video,
            TelegramMessageType::VOICE => MessageType::Voice,
            TelegramMessageType::AUDIO => MessageType::Audio,
            TelegramMessageType::STICKER => MessageType::Sticker,
            TelegramMessageType::LOCATION, TelegramMessageType::VENUE => MessageType::Location,
            TelegramMessageType::CONTACT => MessageType::Contact,
            TelegramMessageType::POLL => MessageType::Poll,
            default => MessageType::Unsupported,
        };
    }

    private function body(Message $message, MessageType $type): ?string
    {
        if (filled($message->text)) {
            return (string) $message->text;
        }

        if (filled($message->caption)) {
            return (string) $message->caption;
        }

        return match ($type) {
            MessageType::Photo => '[photo]',
            MessageType::Document => '[document]',
            MessageType::Video => '[video]',
            MessageType::Voice => '[voice]',
            MessageType::Audio => '[audio]',
            MessageType::Sticker => '[sticker]',
            MessageType::Location => '[location]',
            MessageType::Contact => '[contact]',
            MessageType::Poll => '[poll]',
            default => '[unsupported]',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Message $message, MessageType $type, ?TelegramMessageType $telegramType): array
    {
        $telegram = array_filter([
            'telegram_type' => $telegramType?->value,
            'caption' => $message->caption,
            'via_bot' => $message->via_bot?->username,
            'message_thread_id' => $message->message_thread_id,
            'media_group_id' => $message->media_group_id ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $file = $this->fileMetadata($message, $type);
        if ($file !== []) {
            $telegram['file'] = $file;
        }

        if ($message->location !== null) {
            $telegram['location'] = [
                'latitude' => $message->location->latitude ?? null,
                'longitude' => $message->location->longitude ?? null,
            ];
        }

        if ($message->contact !== null) {
            $telegram['contact'] = array_filter([
                'phone_present' => filled($message->contact->phone_number ?? null),
                'first_name' => $message->contact->first_name ?? null,
                'last_name' => $message->contact->last_name ?? null,
            ], static fn ($value) => $value !== null);
        }

        if ($message->poll !== null) {
            $telegram['poll'] = [
                'question' => $message->poll->question ?? null,
                'type' => isset($message->poll->type) ? (is_string($message->poll->type) ? $message->poll->type : $message->poll->type->value ?? null) : null,
            ];
        }

        if ($message->forward_origin !== null || isset($message->forward_from) || isset($message->forward_from_chat)) {
            $telegram['forwarded'] = true;
        }

        if (is_array($message->entities) && $message->entities !== []) {
            $telegram['entities'] = array_map(static function ($entity): array {
                return array_filter([
                    'type' => is_object($entity) && isset($entity->type) ? (is_string($entity->type) ? $entity->type : $entity->type->value ?? null) : null,
                    'offset' => is_object($entity) ? ($entity->offset ?? null) : null,
                    'length' => is_object($entity) ? ($entity->length ?? null) : null,
                ], static fn ($value) => $value !== null);
            }, array_slice($message->entities, 0, 20));
        }

        return ['telegram' => $telegram];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(Message $message, MessageType $type): array
    {
        $file = match ($type) {
            MessageType::Photo => $this->largestPhoto($message),
            MessageType::Document => $message->document,
            MessageType::Video => $message->video ?? $message->video_note,
            MessageType::Voice => $message->voice,
            MessageType::Audio => $message->audio,
            MessageType::Sticker => $message->sticker,
            default => null,
        };

        if ($file === null) {
            return [];
        }

        return array_filter([
            'file_id' => $file->file_id ?? null,
            'file_unique_id' => $file->file_unique_id ?? null,
            'file_name' => $file->file_name ?? null,
            'mime_type' => $file->mime_type ?? null,
            'file_size' => $file->file_size ?? null,
            'width' => $file->width ?? null,
            'height' => $file->height ?? null,
            'duration' => $file->duration ?? null,
            'emoji' => $file->emoji ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function largestPhoto(Message $message): mixed
    {
        $photos = $message->photo ?? [];

        if ($photos === []) {
            return $message->live_photo ?? null;
        }

        return $photos[array_key_last($photos)] ?? null;
    }

    private function displayName(?string $first, ?string $last, ?string $username): string
    {
        $name = trim(trim((string) $first).' '.trim((string) $last));

        if ($name !== '') {
            return $name;
        }

        if (filled($username)) {
            return (string) $username;
        }

        return 'Unknown';
    }
}
