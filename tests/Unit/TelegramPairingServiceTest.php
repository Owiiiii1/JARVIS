<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Telegram\Pairing\TelegramInboundContext;
use App\Services\Telegram\Pairing\TelegramPairingMessages;
use App\Services\Telegram\Pairing\TelegramPairingOutcome;
use App\Services\Telegram\Pairing\TelegramPairingService;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramPairingServiceTest extends TestCase
{
    private TelegramPairingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TelegramPairingService::class);
    }

    public function test_invalid_code_is_rejected(): void
    {
        $context = $this->sampleContext('900001');

        $result = $this->service->attemptPairing($context, '000000');

        $this->assertSame(TelegramPairingOutcome::InvalidCode, $result->outcome);
        $this->assertSame([TelegramPairingMessages::INVALID_CODE], $result->messages);
    }

    public function test_disabled_user_cannot_pair(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser(UserStatus::Disabled);
            $context = $this->sampleContext('900002');
            $result = $this->service->attemptPairing($context, $user->access_code);

            $this->assertSame(TelegramPairingOutcome::DisabledUser, $result->outcome);
            $this->assertNull(ChannelIdentity::findTelegramByExternalUserId('900002'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_active_user_code_creates_identity(): void
    {
        $user = null;
        $externalUserId = '900003';

        try {
            $user = $this->createTemporaryUser(UserStatus::Active);
            $context = $this->sampleContext($externalUserId);
            $result = $this->service->attemptPairing($context, $user->access_code);

            $this->assertSame(TelegramPairingOutcome::Paired, $result->outcome);
            $this->assertNotNull($result->identity);
            $this->assertSame($user->id, $result->identity->user_id);

            $identity = ChannelIdentity::findTelegramByExternalUserId($externalUserId);
            $this->assertNotNull($identity);
            $this->assertSame($user->id, $identity->user_id);
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_duplicate_pairing_is_idempotent(): void
    {
        $user = null;
        $externalUserId = '900004';

        try {
            $user = $this->createTemporaryUser(UserStatus::Active);
            $context = $this->sampleContext($externalUserId);

            $first = $this->service->attemptPairing($context, $user->access_code);
            $second = $this->service->attemptPairing($context, $user->access_code);

            $this->assertSame(TelegramPairingOutcome::Paired, $first->outcome);
            $this->assertSame(TelegramPairingOutcome::AlreadyLinked, $second->outcome);
            $this->assertSame(1, ChannelIdentity::query()->where('external_user_id', $externalUserId)->count());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_user_cannot_receive_second_telegram_identity(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser(UserStatus::Active);
            $firstContext = $this->sampleContext('900005');
            $this->service->attemptPairing($firstContext, $user->access_code);

            $secondContext = $this->sampleContext('900006');
            $result = $this->service->attemptPairing($secondContext, $user->access_code);

            $this->assertSame(TelegramPairingOutcome::UserAlreadyHasTelegram, $result->outcome);
            $this->assertNull(ChannelIdentity::findTelegramByExternalUserId('900006'));
        } finally {
            $this->deleteTelegramIdentity('900005');
            $this->deleteTelegramIdentity('900006');
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_code_pairing_works_at_service_level(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        if (ChannelIdentity::findTelegramForUser($owner->id) !== null) {
            $this->markTestSkipped('Owner Telegram identity already exists in production.');

            return;
        }

        $externalUserId = '900007';

        try {
            $context = $this->sampleContext($externalUserId);
            $result = $this->service->attemptPairing($context, AccessCodeGenerator::OWNER_CODE);

            $this->assertSame(TelegramPairingOutcome::Paired, $result->outcome);
            $this->assertSame($owner->id, $result->identity?->user_id);
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
        }
    }

    private function sampleContext(string $externalUserId): TelegramInboundContext
    {
        return new TelegramInboundContext(
            externalUserId: $externalUserId,
            externalChatId: $externalUserId,
            username: 'jarvis_test',
            firstName: 'Jarvis',
            lastName: 'Test',
        );
    }

    private function createTemporaryUser(UserStatus $status): User
    {
        $generator = app(AccessCodeGenerator::class);

        return User::query()->create([
            'name' => 'Jarvis Telegram Test User',
            'email' => 'jarvis-test-'.Str::lower(Str::random(12)).'@invalid.local',
            'password' => Hash::make('temporary-test-password'),
            'role' => UserRole::User,
            'access_code' => $generator->generate(),
            'status' => $status,
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
        if (! str_starts_with($externalUserId, '9000')) {
            return;
        }

        ChannelIdentity::query()
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->where('external_user_id', $externalUserId)
            ->delete();
    }
}
