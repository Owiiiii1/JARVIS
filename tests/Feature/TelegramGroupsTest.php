<?php

namespace Tests\Feature;

use App\Enums\ConversationKind;
use App\Enums\MessageType;
use App\Enums\TelegramGroupStatus;
use App\Enums\UserRole;
use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Project;
use App\Models\TelegramBotSetting;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupParticipant;
use App\Models\Topic;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Groups\GroupMessagingService;
use App\Services\Groups\TelegramGroupDiscoveryService;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Projects\ProjectContextService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\TestCase;

class TelegramGroupsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_telegram_groups_schema(): void
    {
        $this->assertTrue(Schema::hasTable('telegram_groups'));
        $this->assertTrue(Schema::hasColumns('telegram_groups', [
            'telegram_chat_id',
            'conversation_id',
            'title',
            'username',
            'chat_type',
            'status',
            'timezone',
            'first_seen_at',
            'last_seen_at',
            'last_message_at',
            'message_count',
            'settings',
            'metadata',
        ]));
        $this->assertTrue(Schema::hasTable('telegram_group_participants'));
        $this->assertTrue(Schema::hasColumns('telegram_group_participants', [
            'telegram_group_id',
            'telegram_user_id',
            'username',
            'first_name',
            'last_name',
            'display_name',
            'is_bot',
            'first_seen_at',
            'last_seen_at',
        ]));
        $this->assertTrue(Schema::hasColumns('messages', [
            'telegram_group_id',
            'sender_external_id',
            'sender_username',
            'sender_name',
            'reply_to_channel_message_id',
            'thread_id',
            'edited_at',
        ]));
        $this->assertTrue(Schema::hasTable('project_groups'));
    }

    public function test_first_group_update_registers_group_and_conversation_once(): void
    {
        $chatId = '-910001001';
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);
        Bus::fake([AnalyzeConversationTurnJob::class, UpdateConversationSummaryJob::class]);

        try {
            $this->postGroupText($chatId, '910101', 'Hello group', 910101, 11);
            $this->postGroupText($chatId, '910101', 'Hello group', 910102, 11);

            $groups = TelegramGroup::query()->where('telegram_chat_id', $chatId)->get();
            $this->assertCount(1, $groups);
            $group = $groups->first();
            $this->assertSame(ConversationKind::Group, $group->conversation->kind);
            $this->assertSame(TelegramGroupStatus::Connected, $group->status);
            $this->assertSame(TelegramGroup::MODE_PERSIST_ONLY, $group->mode());
            $this->assertSame(1, Conversation::query()->where('kind', ConversationKind::Group)->whereKey($group->conversation_id)->count());
            $this->assertSame(1, Message::query()->where('conversation_id', $group->conversation_id)->count());
            $this->assertSame(1, (int) $group->fresh()->message_count);
            $this->assertSame(0, count($fake->conversationCalls()));
            Bus::assertNotDispatched(AnalyzeConversationTurnJob::class);
            $this->assertSame(0, Topic::query()->where('user_id', $group->conversation->user_id)->where('name', 'Hello group')->count());
            $this->assertSame(0, Memory::query()->where('user_id', $group->conversation->user_id)->where('content', 'Hello group')->count());
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_duplicate_first_updates_do_not_create_two_groups(): void
    {
        $chatId = '-910001002';
        $discovery = app(TelegramGroupDiscoveryService::class);

        try {
            $first = $discovery->discoverOrCreate($chatId, ['title' => 'Race A', 'chat_type' => 'supergroup']);
            $second = $discovery->discoverOrCreate($chatId, ['title' => 'Race A', 'chat_type' => 'supergroup']);

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, TelegramGroup::query()->where('telegram_chat_id', $chatId)->count());
            $this->assertSame(1, Conversation::query()->where('kind', ConversationKind::Group)->where('id', $first->conversation_id)->count());
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_group_text_and_participant_persist_and_refresh(): void
    {
        $chatId = '-910001003';

        try {
            $this->postGroupText($chatId, '910201', 'First', 910201, 21, 'Alpha', 'one');
            $this->postGroupText($chatId, '910201', 'Second', 910202, 22, 'Alpha', 'two', 'alphatwo');

            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);
            $this->assertSame(2, Message::query()->where('conversation_id', $group->conversation_id)->count());
            $participant = TelegramGroupParticipant::query()
                ->where('telegram_group_id', $group->id)
                ->where('telegram_user_id', '910201')
                ->first();
            $this->assertNotNull($participant);
            $this->assertSame('alphatwo', $participant->username);
            $this->assertSame('Alpha two', $participant->display_name);
            $this->assertFalse($participant->is_bot);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_private_dm_path_still_works_and_two_groups_share_message_ids(): void
    {
        $user = null;
        $externalUserId = '920401';
        $chatA = '-910001004';
        $chatB = '-910001005';
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postJson('/telegram/webhook', [
                'update_id' => 920401,
                'message' => [
                    'message_id' => 8,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                    'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => 'personal ping',
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $this->assertGreaterThan(0, count($fake->conversationCalls()));
            $this->assertSame(1, Message::query()->where('user_id', $user->id)->where('body', 'personal ping')->count());

            $this->postGroupText($chatA, '910301', 'shared-id', 910301, 9);
            $this->postGroupText($chatB, '910302', 'shared-id', 910302, 9);

            $this->assertSame(1, TelegramGroup::query()->where('telegram_chat_id', $chatA)->count());
            $this->assertSame(1, TelegramGroup::query()->where('telegram_chat_id', $chatB)->count());
            $this->assertSame(2, Message::query()->where('channel_message_id', '9')->whereNotNull('telegram_group_id')->count());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
            $this->deleteTestTelegramGroup($chatA);
            $this->deleteTestTelegramGroup($chatB);
        }
    }

    public function test_non_text_reply_thread_and_edited_message(): void
    {
        $chatId = '-910001006';

        try {
            $this->postJson('/telegram/webhook', [
                'update_id' => 910401,
                'message' => [
                    'message_id' => 40,
                    'date' => time(),
                    'message_thread_id' => 77,
                    'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Media group', 'is_forum' => true],
                    'from' => ['id' => 910401, 'is_bot' => false, 'first_name' => 'Media'],
                    'photo' => [[
                        'file_id' => 'file-photo-1',
                        'file_unique_id' => 'uniq-photo-1',
                        'width' => 8,
                        'height' => 8,
                        'file_size' => 32,
                    ]],
                    'caption' => 'a photo',
                    'reply_to_message' => [
                        'message_id' => 39,
                        'date' => time() - 10,
                        'chat' => ['id' => (int) $chatId, 'type' => 'supergroup'],
                    ],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);
            $photo = Message::query()->where('conversation_id', $group->conversation_id)->first();
            $this->assertSame(MessageType::Photo, $photo->message_type);
            $this->assertSame('a photo', $photo->body);
            $this->assertSame('39', $photo->reply_to_channel_message_id);
            $this->assertSame('77', $photo->thread_id);
            $this->assertSame('file-photo-1', $photo->metadata['telegram']['file']['file_id'] ?? null);

            $this->postJson('/telegram/webhook', [
                'update_id' => 910402,
                'edited_message' => [
                    'message_id' => 40,
                    'date' => time() - 5,
                    'edit_date' => time(),
                    'message_thread_id' => 77,
                    'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Media group'],
                    'from' => ['id' => 910401, 'is_bot' => false, 'first_name' => 'Media'],
                    'caption' => 'edited caption',
                    'photo' => [[
                        'file_id' => 'file-photo-1',
                        'file_unique_id' => 'uniq-photo-1',
                        'width' => 8,
                        'height' => 8,
                    ]],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $this->assertSame(1, Message::query()->where('conversation_id', $group->conversation_id)->count());
            $photo->refresh();
            $this->assertSame('edited caption', $photo->body);
            $this->assertNotNull($photo->edited_at);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_my_chat_member_left_preserves_history(): void
    {
        $chatId = '-910001007';

        try {
            $this->postGroupText($chatId, '910501', 'keep me', 910501, 51);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);
            $count = Message::query()->where('conversation_id', $group->conversation_id)->count();

            $this->postJson('/telegram/webhook', [
                'update_id' => 910502,
                'my_chat_member' => [
                    'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Keep history'],
                    'from' => ['id' => 910501, 'is_bot' => false, 'first_name' => 'Admin'],
                    'date' => time(),
                    'old_chat_member' => [
                        'status' => 'member',
                        'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Jarvis'],
                    ],
                    'new_chat_member' => [
                        'status' => 'kicked',
                        'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Jarvis'],
                        'until_date' => 0,
                    ],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $group->refresh();
            $this->assertSame(TelegramGroupStatus::Left, $group->status);
            $this->assertSame($count, Message::query()->where('conversation_id', $group->conversation_id)->count());
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_owner_can_list_and_show_group_user_is_denied(): void
    {
        $chatId = '-910001008';
        $user = null;
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        try {
            $this->postGroupText($chatId, '910601', 'visible', 910601, 61);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);

            $this->actingAs($owner)->get(route('telegram-groups.index'))->assertOk();
            $this->actingAs($owner)->get(route('telegram-groups.show', $group))->assertOk();

            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('telegram-groups.index'))->assertForbidden();
            $this->actingAs($user)->get(route('telegram-groups.show', $group))->assertForbidden();
            $this->actingAs($user)->postJson(route('telegram-groups.messages.store', $group), ['body' => 'nope'])->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_admin_outbound_persists_once_and_failed_send_is_not_stored(): void
    {
        $chatId = '-910001009';
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        try {
            $this->postGroupText($chatId, '910701', 'seed', 910701, 71);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $before = Message::query()->where('conversation_id', $group->conversation_id)->count();

            Http::fake([
                'api.telegram.org/*' => Http::sequence()
                    ->push([
                        'ok' => false,
                        'error_code' => 403,
                        'description' => 'Forbidden: bot was kicked from the group chat',
                    ], 403)
                    ->push([
                        'ok' => true,
                        'result' => ['message_id' => 8801, 'text' => 'hello from admin'],
                    ], 200),
            ]);

            $this->actingAs($owner)
                ->postJson(route('telegram-groups.messages.store', $group), ['body' => 'should fail'])
                ->assertStatus(422);

            $this->assertSame($before, Message::query()->where('conversation_id', $group->conversation_id)->count());
            $this->assertSame(TelegramGroupStatus::Left, $group->fresh()->status);

            TelegramGroup::query()->whereKey($group->id)->update([
                'status' => TelegramGroupStatus::Connected->value,
            ]);
            $group = TelegramGroup::query()->findOrFail($group->id);
            $this->assertSame(TelegramGroupStatus::Connected, $group->status);

            $this->actingAs($owner)
                ->postJson(route('telegram-groups.messages.store', $group), ['body' => 'hello from admin'])
                ->assertOk();

            $this->assertSame(1, Message::query()->where('conversation_id', $group->conversation_id)->where('channel_message_id', '8801')->count());
            $outbound = Message::query()->where('conversation_id', $group->conversation_id)->where('channel_message_id', '8801')->first();
            $this->assertTrue((bool) ($outbound->metadata['group_outbound'] ?? false));

            $this->postJson('/telegram/webhook', [
                'update_id' => 910799,
                'message' => [
                    'message_id' => 8801,
                    'date' => time(),
                    'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Outbound'],
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Jarvis'],
                    'text' => 'hello from admin',
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $this->assertSame(1, Message::query()->where('conversation_id', $group->conversation_id)->where('channel_message_id', '8801')->count());
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_timezone_validation_and_fallback(): void
    {
        $chatId = '-910001010';
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        try {
            $this->postGroupText($chatId, '910801', 'tz', 910801, 81);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNull($group->timezone);
            $this->assertSame($owner->timezone ?: config('app.timezone'), $group->effectiveTimezone($owner->timezone));

            $this->actingAs($owner)->patch(route('telegram-groups.update', $group), [
                'timezone' => 'Not/AZone',
            ])->assertSessionHasErrors('timezone');

            $this->actingAs($owner)->patch(route('telegram-groups.update', $group), [
                'timezone' => 'Europe/Kyiv',
            ])->assertRedirect();

            $this->assertSame('Europe/Kyiv', $group->fresh()->timezone);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_group_turn_is_rejected_and_project_groups_attach_without_raw_context(): void
    {
        $chatId = '-910001011';
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);
        $name = 'jarvis-test-project-'.Str::lower(Str::random(8));
        $project = null;

        try {
            $this->postGroupText($chatId, '910901', 'project attach', 910901, 91);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);
            $group->loadMissing('conversation');

            try {
                app(ConversationTurnService::class)->handleUserMessage(
                    $owner,
                    $group->conversation,
                    'should not run',
                    new \App\Services\Conversations\ChannelContext(
                        channel: \App\Enums\MessageChannel::Telegram,
                        channelMessageId: 'nope',
                    ),
                );
                $this->fail('Group conversation must not enter personal Conversation AI.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Group conversations', $exception->getMessage());
            }

            $project = app(ProjectService::class)->create($owner, $name, 'tmp');
            app(ProjectService::class)->attachGroup($owner, $project, $group);
            $this->assertTrue($project->telegramGroups()->whereKey($group->id)->exists());

            $context = app(ProjectContextService::class)->context($owner, $project);
            $this->assertArrayHasKey('groups', $context);
            $this->assertSame($group->id, $context['groups'][0]['id'] ?? null);
            $this->assertArrayNotHasKey('messages', $context['groups'][0] ?? []);

            $package = app(PersonalMemoryRetriever::class)->retrieve($owner, $group->conversation, 'project attach');
            $this->assertNull($package->currentSummary);
        } finally {
            if ($project !== null) {
                $project->telegramGroups()->detach();
                $project->delete();
            }
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_anonymous_sender_chat_does_not_create_participant(): void
    {
        $chatId = '-910001012';

        try {
            $this->postJson('/telegram/webhook', [
                'update_id' => 911001,
                'message' => [
                    'message_id' => 12,
                    'date' => time(),
                    'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Anon'],
                    'sender_chat' => [
                        'id' => (int) $chatId,
                        'title' => 'Anon',
                        'type' => 'supergroup',
                    ],
                    'text' => 'anonymous admin',
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
            ])->assertOk();

            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $this->assertNotNull($group);
            $this->assertSame(0, TelegramGroupParticipant::query()->where('telegram_group_id', $group->id)->count());
            $message = Message::query()->where('conversation_id', $group->conversation_id)->first();
            $this->assertSame('Anon', $message->sender_name);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    private function postGroupText(
        string $chatId,
        string $fromId,
        string $text,
        int $updateId,
        int $messageId,
        string $firstName = 'Test',
        ?string $lastName = null,
        ?string $username = null,
    ): void {
        $from = [
            'id' => (int) $fromId,
            'is_bot' => false,
            'first_name' => $firstName,
        ];

        if ($lastName !== null) {
            $from['last_name'] = $lastName;
        }

        if ($username !== null) {
            $from['username'] = $username;
        }

        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Jarvis Test Group'],
                'from' => $from,
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
        ])->assertOk();
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);
        $this->assertNotEmpty($setting->webhook_secret);

        return (string) $setting->webhook_secret;
    }
}
