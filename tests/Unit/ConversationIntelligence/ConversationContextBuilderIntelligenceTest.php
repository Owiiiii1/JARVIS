<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ToolExecutionLogStatus;
use App\Models\AiRoleSetting;
use App\Models\Message;
use App\Models\ToolExecutionLog;
use App\Models\UserAssistantProfile;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\ConversationIntelligence\WorkingContextBuilder;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ConversationContextBuilderIntelligenceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_working_context_and_policy_are_injected_without_prompt_bloat_keys(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Открой проект YFS',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build($user, $conversation, $configuration, $inbound);

            $this->assertStringContainsString('Conversational intelligence', $context['system_prompt']);
            $this->assertStringContainsString('Conversational working context', $context['system_prompt']);
            $this->assertStringContainsString('Web, Voice, and Telegram', $context['system_prompt']);
            $this->assertArrayHasKey('continuity_source', $context['diagnostics']);
            $this->assertArrayHasKey('topic_mode', $context['diagnostics']);
            $this->assertArrayHasKey('working', $context);
            $this->assertTrue($context['working']->available);
            $this->assertContains('Открой проект YFS', array_map(static fn ($message): string => $message->content, $context['messages']));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_temporary_style_does_not_rewrite_the_assistant_profile(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $profile = UserAssistantProfile::query()->create([
                'user_id' => $user->id,
                'assistant_name' => 'Jarvis',
                'personality' => 'спокойный',
                'interaction_style' => 'обычный',
                'about_user' => 'инженер',
                'onboarding_status' => 'completed',
            ]);
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'отвечай сейчас максимально коротко',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build($user, $conversation, $configuration, $inbound);

            $this->assertStringContainsString('Temporary conversation style', $context['system_prompt']);
            $profile->refresh();
            $this->assertSame('Jarvis', $profile->assistant_name);
            $this->assertSame('спокойный', $profile->personality);
            $this->assertSame('обычный', $profile->interaction_style);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_working_intelligence_failure_falls_back_to_the_normal_engine(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Ты тут?',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build(
                $user,
                $conversation,
                $configuration,
                $inbound,
                null,
                [],
                WorkingContext::unavailable(),
            );

            $this->assertStringContainsString((string) $configuration->system_prompt, $context['system_prompt']);
            $this->assertContains('Ты тут?', array_map(static fn ($message): string => $message->content, $context['messages']));
            $this->assertFalse($context['working']->available);
            $this->assertSame('fallback', $context['diagnostics']['continuity_source']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_recent_created_task_reference_is_trusted_until_it_expires(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            ToolExecutionLog::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tool_name' => 'create_task',
                'status' => ToolExecutionLogStatus::Succeeded,
                'metadata' => ['task_id' => 77, 'title' => 'купить фильтр для станка'],
                'started_at' => now()->subMinute(),
                'finished_at' => now()->subMinute(),
            ]);
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Напомни про неё завтра утром',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $working = app(WorkingContextBuilder::class)->build($user, $conversation, $inbound);
            $trusted = $working->uniqueTrustedTask();

            $this->assertNotNull($trusted);
            $this->assertSame(77, $trusted->id);
            $this->assertTrue($working->allowsTrustedMutation());
            $this->assertStringContainsString('купить фильтр', $working->promptBlock());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
