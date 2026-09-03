<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CleansTemporaryJarvisRecords
{
    private function createTemporaryUser(): User
    {
        $generator = app(AccessCodeGenerator::class);

        return User::query()->create([
            'name' => 'Jarvis Conversation Test User',
            'email' => 'jarvis-test-'.Str::lower(Str::random(12)).'@invalid.local',
            'password' => Hash::make('temporary-test-password'),
            'role' => UserRole::User,
            'access_code' => $generator->generate(),
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);
    }

    private function createTemporaryTelegramIdentity(User $user, string $externalUserId): ChannelIdentity
    {
        return ChannelIdentity::query()->create([
            'user_id' => $user->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
            'external_user_id' => $externalUserId,
            'external_chat_id' => $externalUserId,
            'username' => 'jarvis_test',
            'first_name' => 'Jarvis',
            'last_name' => 'Test',
            'linked_at' => now(),
            'last_seen_at' => now(),
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

        Reminder::query()->where('user_id', $user->id)->delete();
        Message::query()->where('user_id', $user->id)->delete();
        UserAiSetting::query()->where('user_id', $user->id)->delete();
        ChannelIdentity::query()->where('user_id', $user->id)->update(['active_conversation_id' => null]);
        Conversation::query()->where('user_id', $user->id)->delete();
        ChannelIdentity::query()->where('user_id', $user->id)->delete();
        User::query()->whereKey($user->id)->delete();
    }

    private function deleteTelegramIdentity(string $externalUserId): void
    {
        if (! preg_match('/^(9\d{5})$/', $externalUserId)) {
            return;
        }

        $identity = ChannelIdentity::findTelegramByExternalUserId($externalUserId);

        if ($identity === null) {
            return;
        }

        $identity->forceFill(['active_conversation_id' => null])->save();
        $identity->delete();
    }
}
