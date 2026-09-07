<?php

namespace Tests\Unit\Conversations;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ToolConfirmationStatus;
use App\Enums\UserRole;
use App\Models\Message;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\MessageHistoryService;
use App\Services\Tools\ToolConfirmationService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class MessageHistoryConfirmationTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_serializer_marks_executed_confirmation_as_executed_not_pending(): void
    {
        $owner = null;

        try {
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = app(ToolConfirmationService::class)->createPending(
                $owner,
                $chat,
                'send_gmail_message',
                ['to' => ['anna@example.test'], 'subject' => 'Hello', 'body' => 'Hi'],
            );
            $confirmation->forceFill([
                'status' => ToolConfirmationStatus::Executed,
                'executed_at' => now(),
            ])->save();

            $message = Message::query()->create([
                'conversation_id' => $chat->id,
                'user_id' => $owner->id,
                'role' => MessageRole::Assistant,
                'channel' => MessageChannel::Web,
                'body' => 'Confirm send?',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
                'metadata' => [
                    'pending_confirmation' => [
                        'id' => $confirmation->public_id,
                        'tool_name' => 'send_gmail_message',
                        'summary' => 'Send email to anna@example.test — Hello',
                        'preview' => ['to' => ['anna@example.test'], 'subject' => 'Hello'],
                        'expires_at' => now()->addMinutes(10)->toIso8601String(),
                    ],
                ],
            ]);

            $payload = app(MessageHistoryService::class)->toArray($message);

            $this->assertSame('executed', $payload['pending_confirmation']['status']);
            $this->assertSame($confirmation->public_id, $payload['pending_confirmation']['id']);
            $this->assertNotSame('pending', $payload['pending_confirmation']['status']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_serializer_treats_past_expiry_as_expired_even_if_row_is_still_pending(): void
    {
        $owner = null;

        try {
            $owner = $this->createTemporaryUser();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = app(ToolConfirmationService::class)->createPending(
                $owner,
                $chat,
                'delete_calendar_event',
                ['event_id' => 'evt-1'],
            );
            $confirmation->forceFill(['expires_at' => now()->subMinute()])->save();

            $message = Message::query()->create([
                'conversation_id' => $chat->id,
                'user_id' => $owner->id,
                'role' => MessageRole::Assistant,
                'channel' => MessageChannel::Web,
                'body' => 'Delete event?',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
                'metadata' => [
                    'pending_confirmation' => [
                        'id' => $confirmation->public_id,
                        'tool_name' => 'delete_calendar_event',
                        'summary' => 'Delete the identified Google Calendar event.',
                    ],
                ],
            ]);

            $payload = app(MessageHistoryService::class)->toArray($message);

            $this->assertSame('expired', $payload['pending_confirmation']['status']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }
}
