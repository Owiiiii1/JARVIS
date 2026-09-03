<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageRole;
use App\Enums\ReminderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\TelegramBotSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationService;
use App\Services\Reminders\ReminderDispatchService;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Tools\CreateReminderTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_reminders_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('reminders'));
        $this->assertTrue(Schema::hasColumns('reminders', [
            'id',
            'user_id',
            'source_conversation_id',
            'source_message_id',
            'text',
            'run_at',
            'timezone',
            'original_local_time',
            'status',
            'delivered_at',
            'cancelled_at',
            'recurrence_rule',
            'last_error',
            'metadata',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_owner_and_user_with_telegram_can_create_reminder(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $this->createTemporaryTelegramIdentity($owner, '940101');
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940102');

            $service = app(ReminderService::class);
            $runAt = CarbonImmutable::now('UTC')->addHour();

            $ownerReminder = $service->create($owner, 'owner task', $runAt, 'Europe/Rome', null, null);
            $userReminder = $service->create($user, 'user task', $runAt->addMinutes(5), 'Europe/Rome', null, null);

            $this->assertSame(ReminderStatus::Scheduled, $ownerReminder->status);
            $this->assertSame(ReminderStatus::Scheduled, $userReminder->status);
            $this->assertSame($owner->id, $ownerReminder->user_id);
            $this->assertSame($user->id, $userReminder->user_id);
        } finally {
            if ($owner !== null) {
                $owner->forceFill(['role' => UserRole::User])->save();
            }
            $this->deleteTelegramIdentity('940101');
            $this->deleteTelegramIdentity('940102');
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_no_telegram_identity_does_not_create_reminder(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->expectException(ReminderException::class);

            try {
                app(ReminderService::class)->create(
                    $user,
                    'buy milk',
                    CarbonImmutable::now('UTC')->addHour(),
                    'Europe/Rome',
                    null,
                    null,
                );
            } catch (ReminderException $exception) {
                $this->assertSame('telegram_not_connected', $exception->error);
                $this->assertSame(0, Reminder::query()->where('user_id', $user->id)->count());
                throw $exception;
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_future_local_datetime_converts_to_utc_and_dst_is_applied(): void
    {
        $service = app(ReminderService::class);

        $winter = $service->localWallTimeToUtc('2026-01-15T11:00:00+01:00', 'Europe/Rome');
        $summer = $service->localWallTimeToUtc('2026-07-15T11:00:00+02:00', 'Europe/Rome');
        $wrongOffset = $service->localWallTimeToUtc('2026-07-15T11:00:00+01:00', 'Europe/Rome');

        $this->assertSame('2026-01-15 10:00:00', $winter->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-15 09:00:00', $summer->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-15 09:00:00', $wrongOffset->format('Y-m-d H:i:s'));
    }

    public function test_past_time_is_rejected(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940103');

            $this->expectException(ReminderException::class);

            try {
                app(ReminderService::class)->create(
                    $user,
                    'too late',
                    CarbonImmutable::now('UTC')->subMinute(),
                    'Europe/Rome',
                    null,
                    null,
                );
            } catch (ReminderException $exception) {
                $this->assertSame('past_time', $exception->error);
                throw $exception;
            }
        } finally {
            $this->deleteTelegramIdentity('940103');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_source_conversation_and_message_are_stored(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940104');
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => 'telegram',
                'body' => 'Напомни завтра в 11 сходить в магазин',
                'message_type' => 'text',
                'occurred_at' => now(),
            ]);

            $reminder = app(ReminderService::class)->create(
                $user,
                'сходить в магазин',
                CarbonImmutable::now('UTC')->addDay(),
                'Europe/Rome',
                $conversation,
                $message,
            );

            $this->assertSame($conversation->id, $reminder->source_conversation_id);
            $this->assertSame($message->id, $reminder->source_message_id);
        } finally {
            $this->deleteTelegramIdentity('940104');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_telegram_and_web_share_the_same_tool_loop(): void
    {
        $user = null;
        $externalUserId = '940105';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $runAtLocal = CarbonImmutable::now($user->timezone)->addHour()->format('Y-m-d\\TH:i:sP');

            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'проверить Jarvis',
                'run_at_local' => $runAtLocal,
            ], 'Хорошо, напомню проверить Jarvis.');

            $this->postTelegramUpdate($externalUserId, 'Напомни через час проверить Jarvis', 940105, 81);

            $conversation = Conversation::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($conversation);
            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(2, count($fake->conversationCalls()));
            $this->assertNotEmpty($fake->conversationCalls()[0]['request']->tools);
            $this->assertTrue(
                collect($fake->calls[1]['request']->messages)->contains(
                    fn (AiChatMessage $message): bool => $message->role === 'tool'
                )
            );

            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'вторая задача',
                'run_at_local' => CarbonImmutable::now($user->timezone)->addHours(2)->format('Y-m-d\\TH:i:sP'),
            ], 'Хорошо, напомню вторую задачу.');

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Напомни через два часа вторую задачу',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk();

            $this->assertSame(2, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(
                ['проверить Jarvis', 'вторая задача'],
                Reminder::query()->where('user_id', $user->id)->orderBy('id')->pluck('text')->all()
            );

            $webReminder = Reminder::query()->where('user_id', $user->id)->orderBy('id')->get()->last();
            $this->assertNotNull($webReminder->source_conversation_id);
            $this->assertNotNull($webReminder->source_message_id);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_duplicate_telegram_inbound_does_not_create_duplicate_reminder(): void
    {
        $user = null;
        $externalUserId = '940106';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $runAtLocal = CarbonImmutable::now($user->timezone)->addHour()->format('Y-m-d\\TH:i:sP');
            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'одно напоминание',
                'run_at_local' => $runAtLocal,
            ], 'Хорошо, напомню.');

            $this->postTelegramUpdate($externalUserId, 'Напомни через час', 940106, 90);
            $this->postTelegramUpdate($externalUserId, 'Напомни через час', 940106, 90);

            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(2, count($fake->conversationCalls()));
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_tool_reports_telegram_not_connected_without_creating_a_row(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);
            $runAtLocal = CarbonImmutable::now($user->timezone)->addHour()->format('Y-m-d\\TH:i:sP');
            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'магазин',
                'run_at_local' => $runAtLocal,
            ], 'Для получения напоминаний сначала подключите Telegram.');

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Напомни завтра в 11 сходить в магазин',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk();

            $this->assertSame(0, Reminder::query()->where('user_id', $user->id)->count());
            $toolMessage = collect($fake->calls[1]['request']->messages)->first(
                fn (AiChatMessage $message): bool => $message->role === 'tool'
            );
            $this->assertNotNull($toolMessage);
            $this->assertSame('telegram_not_connected', $toolMessage->toolResponse['error'] ?? null);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_max_tool_rounds_prevents_a_loop(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940107');
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);

            $loop = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('loop', 'loop_forever', [])],
            );
            $fake->script = array_fill(0, ConversationAiService::MAX_TOOL_ROUNDS + 1, $loop);

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'loop please',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk()->assertJsonPath('error', ConversationAiService::AI_FAILURE);

            $this->assertSame(ConversationAiService::MAX_TOOL_ROUNDS + 1, count($fake->conversationCalls()));
            $this->assertSame(0, Reminder::query()->where('user_id', $user->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity('940107');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_stale_pending_turn_is_retried_and_same_source_does_not_duplicate_reminder(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940111');
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);
            $runAtLocal = CarbonImmutable::now($user->timezone)->addHour()->format('Y-m-d\\TH:i:sP');

            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => 'telegram',
                'body' => 'Напомни через час проверить Jarvis',
                'message_type' => 'text',
                'occurred_at' => now()->subMinutes(3),
            ]);

            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'проверить Jarvis',
                'run_at_local' => $runAtLocal,
            ], 'Хорошо, напомню проверить Jarvis.');

            app(ConversationAiService::class)->completeUserTurn($inbound->fresh());
            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());

            Message::query()->where('parent_message_id', $inbound->id)->where('role', MessageRole::Assistant)->delete();
            $inbound->forceFill([
                'metadata' => ['ai' => ['status' => 'pending']],
            ])->save();
            Message::query()->whereKey($inbound->id)->update([
                'updated_at' => now()->subMinutes(2),
            ]);

            $fake->queueToolThenText(CreateReminderTool::NAME, [
                'text' => 'проверить Jarvis',
                'run_at_local' => $runAtLocal,
            ], 'Уже создано.');

            app(ConversationAiService::class)->completeUserTurn($inbound->fresh());

            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, Message::query()->where('parent_message_id', $inbound->id)->where('role', MessageRole::Assistant)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity('940111');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_fallback_reply_when_follow_up_ai_fails_after_tool(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940112');
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);
            $runAtLocal = CarbonImmutable::now($user->timezone)->addHour()->format('Y-m-d\\TH:i:sP');

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('call_1', CreateReminderTool::NAME, [
                    'text' => 'проверить Jarvis',
                    'run_at_local' => $runAtLocal,
                ])],
            );
            $fake->script[] = function () {
                throw new \RuntimeException('follow-up failed');
            };

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Напомни через час проверить Jarvis',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk();

            $assistant = Message::query()->where('user_id', $user->id)->where('role', MessageRole::Assistant)->first();
            $this->assertNotNull($assistant);
            $this->assertStringContainsString('напомню', mb_strtolower($assistant->body));
            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity('940112');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_scheduler_delivers_due_reminder_once_and_handles_telegram_failure(): void
    {
        $user = null;

        try {
            Http::fake([
                'api.telegram.org/*' => Http::sequence()
                    ->push(['ok' => false, 'description' => 'upstream'], 500)
                    ->push(['ok' => true, 'result' => ['message_id' => 1]], 200),
            ]);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940108');
            $reminder = Reminder::query()->create([
                'user_id' => $user->id,
                'text' => 'проверить Jarvis',
                'run_at' => now()->subMinute(),
                'timezone' => 'Europe/Rome',
                'status' => ReminderStatus::Scheduled,
                'metadata' => ['attempts' => 0],
            ]);

            $this->artisan('jarvis:reminders:dispatch')->assertSuccessful();
            $reminder->refresh();
            $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
            $this->assertSame('telegram_delivery_failed', $reminder->last_error);
            $this->assertSame(1, $reminder->metadata['attempts'] ?? null);

            $reminder->forceFill([
                'metadata' => ['attempts' => 1, 'next_retry_at' => now()->utc()->subSecond()->toDateTimeString()],
            ])->save();

            $this->artisan('jarvis:reminders:dispatch')->assertSuccessful();
            $this->artisan('jarvis:reminders:dispatch')->assertSuccessful();

            $reminder->refresh();
            $this->assertSame(ReminderStatus::Delivered, $reminder->status);
            $this->assertNotNull($reminder->delivered_at);

            Http::assertSent(function ($request): bool {
                $data = $request->data();

                return str_contains($request->url(), 'sendMessage')
                    && ($data['text'] ?? '') === '⏰ Напоминание: проверить Jarvis';
            });
        } finally {
            $this->deleteTelegramIdentity('940108');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_same_reminder_cannot_be_claimed_twice(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940109');
            Reminder::query()->create([
                'user_id' => $user->id,
                'text' => 'once',
                'run_at' => now()->subMinute(),
                'timezone' => 'Europe/Rome',
                'status' => ReminderStatus::Scheduled,
                'metadata' => ['attempts' => 0],
            ]);

            $dispatch = app(ReminderDispatchService::class);
            $first = $dispatch->claimDue();
            $second = $dispatch->claimDue();

            $this->assertCount(1, $first);
            $this->assertCount(0, $second);
            $this->assertSame(ReminderStatus::Processing, $first[0]->fresh()->status);
        } finally {
            $this->deleteTelegramIdentity('940109');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_disabled_user_is_not_delivered(): void
    {
        $user = null;

        try {
            Http::fake([
                'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
            ]);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, '940110');
            $reminder = Reminder::query()->create([
                'user_id' => $user->id,
                'text' => 'skip me',
                'run_at' => now()->subMinute(),
                'timezone' => 'Europe/Rome',
                'status' => ReminderStatus::Scheduled,
                'metadata' => ['attempts' => 0],
            ]);
            $user->forceFill(['status' => UserStatus::Disabled])->save();

            app(ReminderDispatchService::class)->dispatchDue();

            $reminder->refresh();
            $this->assertSame(ReminderStatus::Cancelled, $reminder->status);
            $this->assertSame('user_disabled', $reminder->metadata['reason'] ?? null);
            Http::assertNothingSent();
        } finally {
            $this->deleteTelegramIdentity('940110');
            $this->deleteTemporaryUser($user);
        }
    }

    private function postTelegramUpdate(string $externalUserId, string $text, int $updateId, int $messageId = 1): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
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
