<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChannelIdentity;
use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use App\Models\TelegramGroupKnowledgeRevision;
use App\Models\TelegramGroupKnowledgeSource;
use App\Models\TelegramGroupParticipant;
use App\Models\Topic;
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

        if ($user->role === UserRole::Owner) {
            $user->forceFill(['role' => UserRole::User])->save();
        }

        \App\Models\Project::query()->where('user_id', $user->id)->delete();
        $memoryIds = \App\Models\Memory::query()->where('user_id', $user->id)->pluck('id');
        \App\Models\MemorySource::query()->whereIn('memory_id', $memoryIds)->delete();
        \App\Models\MemoryRevision::query()->whereIn('memory_id', $memoryIds)->delete();
        \App\Models\Memory::query()->where('user_id', $user->id)->delete();
        $topicIds = \App\Models\Topic::query()->where('user_id', $user->id)->pluck('id');
        \App\Models\MessageTopicRelation::query()->whereIn('topic_id', $topicIds)->delete();
        \App\Models\Topic::query()->where('user_id', $user->id)->delete();
        \App\Models\ConversationSummary::query()->where('user_id', $user->id)->delete();
        \App\Models\MemoryAnalysisRun::query()->where('user_id', $user->id)->delete();
        \App\Models\UserProfile::query()->where('user_id', $user->id)->delete();
        Reminder::query()->where('user_id', $user->id)->delete();
        $conversationIds = Conversation::query()->where('user_id', $user->id)->pluck('id');
        $groupIds = TelegramGroup::query()->whereIn('conversation_id', $conversationIds)->pluck('id');
        $knowledgeIds = TelegramGroupKnowledge::query()->whereIn('telegram_group_id', $groupIds)->pluck('id');
        TelegramGroupKnowledgeRevision::query()->whereIn('knowledge_id', $knowledgeIds)->delete();
        TelegramGroupKnowledgeSource::query()->whereIn('knowledge_id', $knowledgeIds)->delete();
        TelegramGroupKnowledge::query()->whereIn('id', $knowledgeIds)->delete();
        TelegramGroupAnalysisRun::query()->whereIn('telegram_group_id', $groupIds)->delete();
        TelegramGroupParticipant::query()->whereIn('telegram_group_id', $groupIds)->delete();
        Message::query()->whereIn('telegram_group_id', $groupIds)->delete();
        TelegramGroup::query()->whereIn('id', $groupIds)->delete();
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

    private function isTestTelegramChatId(string $telegramChatId): bool
    {
        return (bool) preg_match('/^-91\d{6,12}$/', $telegramChatId);
    }

    private function deleteTestTelegramGroup(string $telegramChatId): void
    {
        if (! $this->isTestTelegramChatId($telegramChatId)) {
            return;
        }

        $group = TelegramGroup::query()->where('telegram_chat_id', $telegramChatId)->first();

        if ($group === null) {
            return;
        }

        $conversationId = (int) $group->conversation_id;
        $group->projects()->detach();
        TelegramGroupKnowledgeRevision::query()
            ->whereIn('knowledge_id', TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->select('id'))
            ->delete();
        TelegramGroupKnowledgeSource::query()
            ->whereIn('knowledge_id', TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->select('id'))
            ->delete();
        TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->delete();
        TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->delete();
        TelegramGroupParticipant::query()->where('telegram_group_id', $group->id)->delete();
        Message::query()->where('conversation_id', $conversationId)->delete();
        $group->delete();
        Conversation::query()
            ->whereKey($conversationId)
            ->where('kind', ConversationKind::Group)
            ->delete();
    }
}
