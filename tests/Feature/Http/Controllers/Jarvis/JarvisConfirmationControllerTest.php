<?php

namespace Tests\Feature\Http\Controllers\Jarvis;

use App\Enums\ToolConfirmationStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\ToolConfirmation;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use App\Services\Tools\ToolConfirmationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class JarvisConfirmationControllerTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_duplicate_confirm_returns_already_resolved_without_a_second_action(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = $this->persistedConfirmation($owner, $chat, ToolConfirmationStatus::Executed);
            Http::preventStrayRequests();

            $first = $this->actingAs($owner)->postJson(route('jarvis.confirmations.confirm', $confirmation->public_id), [
                'client_message_id' => (string) Str::uuid(),
            ]);
            $first->assertOk();
            $first->assertJsonPath('already_resolved', true);
            $first->assertJsonPath('confirmation.id', $confirmation->public_id);
            $first->assertJsonPath('confirmation.status', 'executed');
            $first->assertJsonPath('inbound', null);
            $first->assertJsonPath('assistant', null);

            $second = $this->actingAs($owner)->postJson(route('jarvis.confirmations.confirm', $confirmation->public_id), [
                'client_message_id' => (string) Str::uuid(),
            ]);
            $second->assertOk();
            $second->assertJsonPath('already_resolved', true);
            $this->assertSame(ToolConfirmationStatus::Executed, $confirmation->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_duplicate_cancel_is_harmless_and_does_not_execute(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = $this->persistedConfirmation($owner, $chat, ToolConfirmationStatus::Cancelled);
            Http::preventStrayRequests();

            $response = $this->actingAs($owner)->postJson(route('jarvis.confirmations.cancel', $confirmation->public_id), [
                'client_message_id' => (string) Str::uuid(),
            ]);

            $response->assertOk();
            $response->assertJsonPath('already_resolved', true);
            $response->assertJsonPath('confirmation.status', 'cancelled');
            $this->assertSame(ToolConfirmationStatus::Cancelled, $confirmation->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_expired_confirm_does_not_execute_and_is_not_pending(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = $this->persistedConfirmation($owner, $chat, ToolConfirmationStatus::Pending);
            $confirmation->forceFill(['expires_at' => now()->subMinute()])->save();
            Http::preventStrayRequests();

            $response = $this->actingAs($owner)->postJson(route('jarvis.confirmations.confirm', $confirmation->public_id), [
                'client_message_id' => (string) Str::uuid(),
            ]);

            $response->assertOk();
            $response->assertJsonPath('already_resolved', true);
            $response->assertJsonPath('confirmation.status', 'expired');
            $this->assertNotSame(ToolConfirmationStatus::Executed, $confirmation->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_foreign_confirmation_returns_404(): void
    {
        $owner = null;
        $stranger = null;

        try {
            $owner = $this->temporaryOwner();
            $stranger = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $confirmation = $this->persistedConfirmation($owner, $chat, ToolConfirmationStatus::Pending);

            $this->actingAs($stranger)->postJson(route('jarvis.confirmations.confirm', $confirmation->public_id), [
                'client_message_id' => (string) Str::uuid(),
            ])->assertNotFound();

            $this->assertSame(ToolConfirmationStatus::Pending, $confirmation->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($stranger);
        }
    }

    public function test_guest_is_redirected_from_confirm(): void
    {
        $this->postJson(route('jarvis.confirmations.confirm', (string) Str::uuid()), [
            'client_message_id' => (string) Str::uuid(),
        ])->assertRedirect(route('login'));
    }

    /**
     * @param  User  $owner
     * @param  Conversation  $chat
     */
    private function persistedConfirmation($owner, $chat, ToolConfirmationStatus $status): ToolConfirmation
    {
        $row = app(ToolConfirmationService::class)->createPending(
            $owner,
            $chat,
            'send_gmail_message',
            ['to' => ['anna@example.test'], 'subject' => 'Hello', 'body' => 'Hi'],
            'call-1',
        );

        if ($status !== ToolConfirmationStatus::Pending) {
            $row->forceFill([
                'status' => $status,
                'confirmed_at' => $status === ToolConfirmationStatus::Executed ? now() : null,
                'executed_at' => $status === ToolConfirmationStatus::Executed ? now() : null,
            ])->save();
        }

        return $row->fresh();
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
