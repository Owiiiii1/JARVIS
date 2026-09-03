<?php

namespace Tests\Feature;

use App\Models\ChannelIdentity;
use App\Models\TelegramBotSetting;
use App\Models\User;
use App\Services\Users\AccessCodeGenerator;
use Tests\TestCase;

class TelegramPairingTest extends TestCase
{
    public function test_channel_identities_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('channel_identities'));
    }

    public function test_invalid_webhook_secret_returns_forbidden(): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'invalid-secret',
        ])->assertForbidden();
    }

    public function test_webhook_start_for_unknown_user_does_not_create_identity(): void
    {
        $secret = $this->webhookSecret();
        $externalUserId = '910001';

        try {
            $payload = [
                'update_id' => 910001,
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                    'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => '/start',
                ],
            ];

            $this->postJson('/telegram/webhook', $payload, [
                'X-Telegram-Bot-Api-Secret-Token' => $secret,
            ])->assertOk();

            $this->assertNull(ChannelIdentity::findTelegramByExternalUserId($externalUserId));
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
        }
    }

    public function test_webhook_invalid_code_does_not_create_identity(): void
    {
        $secret = $this->webhookSecret();
        $externalUserId = '910002';

        try {
            $payload = [
                'update_id' => 910002,
                'message' => [
                    'message_id' => 2,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                    'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => '000000',
                ],
            ];

            $this->postJson('/telegram/webhook', $payload, [
                'X-Telegram-Bot-Api-Secret-Token' => $secret,
            ])->assertOk();

            $this->assertNull(ChannelIdentity::findTelegramByExternalUserId($externalUserId));
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
        }
    }

    public function test_webhook_group_message_does_not_create_identity(): void
    {
        $secret = $this->webhookSecret();
        $externalUserId = '910003';

        $payload = [
            'update_id' => 910003,
            'message' => [
                'message_id' => 3,
                'date' => time(),
                'chat' => ['id' => -100123, 'type' => 'supergroup', 'title' => 'Group'],
                'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => '/start',
            ],
        ];

        $this->postJson('/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ])->assertOk();

        $this->assertNull(ChannelIdentity::findTelegramByExternalUserId($externalUserId));
    }

    public function test_webhook_good_code_creates_identity_for_temporary_user(): void
    {
        $user = null;
        $externalUserId = '910004';

        try {
            $user = $this->createTemporaryUser();
            $secret = $this->webhookSecret();

            $payload = [
                'update_id' => 910004,
                'message' => [
                    'message_id' => 4,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                    'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => $user->access_code,
                ],
            ];

            $this->postJson('/telegram/webhook', $payload, [
                'X-Telegram-Bot-Api-Secret-Token' => $secret,
            ])->assertOk();

            $identity = ChannelIdentity::findTelegramByExternalUserId($externalUserId);
            $this->assertNotNull($identity);
            $this->assertSame($user->id, $identity->user_id);
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);
        $this->assertNotEmpty($setting->webhook_secret);

        return (string) $setting->webhook_secret;
    }

    private function createTemporaryUser(): User
    {
        $generator = app(AccessCodeGenerator::class);

        return User::query()->create([
            'name' => 'Jarvis Telegram Webhook Test',
            'email' => 'jarvis-test-'.bin2hex(random_bytes(6)).'@invalid.local',
            'password' => bcrypt('temporary-test-password'),
            'role' => \App\Enums\UserRole::User,
            'access_code' => $generator->generate(),
            'status' => \App\Enums\UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);
    }

    private function deleteTemporaryUser(?User $user): void
    {
        if ($user === null) {
            return;
        }

        if (! str_contains($user->email, '@invalid.local') || ! str_starts_with($user->email, 'jarvis-test-')) {
            return;
        }

        ChannelIdentity::query()->where('user_id', $user->id)->delete();
        User::query()->whereKey($user->id)->delete();
    }

    private function deleteTelegramIdentity(string $externalUserId): void
    {
        if (! str_starts_with($externalUserId, '9100')) {
            return;
        }

        ChannelIdentity::query()
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->where('external_user_id', $externalUserId)
            ->delete();
    }
}
