<?php

namespace Tests\Unit;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Services\Conversations\ConversationService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_create_personal_conversation_belongs_to_user(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $conversation = $service->createPersonal($user, ' Python  ');

            $this->assertSame($user->id, $conversation->user_id);
            $this->assertSame(ConversationKind::Personal, $conversation->kind);
            $this->assertSame('Python', $conversation->title);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_default_osnovnoy_is_created_once(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $service = app(ConversationService::class);

            $first = $service->getOrCreateDefault($user);
            $second = $service->getOrCreateDefault($user);

            $this->assertSame($first->id, $second->id);
            $this->assertSame(Conversation::DEFAULT_TITLE, $first->title);
            $this->assertSame(1, Conversation::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_cannot_set_active_conversation_owned_by_another_user(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $conversationB = $service->createPersonal($userB, 'Private');
            $identityA = $this->createTemporaryTelegramIdentity($userA, '920001');

            $this->assertFalse($service->setActiveConversation($identityA, $conversationB));
            $this->assertNull($identityA->fresh()->active_conversation_id);
        } finally {
            $this->deleteTelegramIdentity('920001');
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_user_lists_only_own_conversations(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $service->createPersonal($userA, 'Alpha');
            $service->createPersonal($userB, 'Secret');

            $titles = $service->listForUser($userA)->pluck('title')->all();

            $this->assertSame(['Alpha'], $titles);
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_rename_updates_owned_conversation_only(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $own = $service->createPersonal($userA, 'Draft');
            $foreign = $service->createPersonal($userB, 'Keep');

            $renamed = $service->rename($userA, $own, '  Работа  ');
            $this->assertSame('Работа', $renamed->title);

            $this->expectException(\InvalidArgumentException::class);
            $service->rename($userA, $foreign, 'Hijack');
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_set_active_conversation_for_owner(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $conversation = $service->createPersonal($user, 'Work');
            $identity = $this->createTemporaryTelegramIdentity($user, '920002');

            $this->assertTrue($service->setActiveConversation($identity, $conversation));
            $this->assertSame($conversation->id, $identity->fresh()->active_conversation_id);
        } finally {
            $this->deleteTelegramIdentity('920002');
            $this->deleteTemporaryUser($user);
        }
    }
}
