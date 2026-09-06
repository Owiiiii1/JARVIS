<?php

namespace Tests\Unit;

use Tests\TestCase;

class WorkspaceConversationDeleteUiTest extends TestCase
{
    public function test_sidebar_exposes_delete_behind_a_menu_not_a_hover_only_control(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $item = (string) file_get_contents(base_path('resources/js/personal-workspace/ConversationSidebarItem.jsx'));

        $this->assertStringContainsString('ConversationSidebarItem', $workspace);
        $this->assertStringContainsString('ConversationDeleteDialog', $workspace);
        $this->assertStringContainsString('onRequestDelete', $item);
        $this->assertStringContainsString('Удалить', $item);
        $this->assertStringContainsString('Переименовать', $item);
        $this->assertStringContainsString('MoreVertical', $item);
        $this->assertStringContainsString('Действия с чатом', $item);
        $this->assertStringNotContainsString('group-hover:flex', $item);
        $this->assertStringNotContainsString('hover-only', $item);
    }

    public function test_confirmation_dialog_is_required_and_cancel_does_not_call_destroy(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $dialog = (string) file_get_contents(base_path('resources/js/personal-workspace/ConversationDeleteDialog.jsx'));

        $this->assertStringContainsString('pendingDelete', $workspace);
        $this->assertStringContainsString('setPendingDelete(item)', $workspace);
        $this->assertStringContainsString('setPendingDelete(null)', $workspace);
        $this->assertStringNotContainsString('window.confirm', $workspace);
        $this->assertStringNotContainsString('window.location.reload', $workspace);
        $this->assertStringContainsString('Удалить этот чат?', $dialog);
        $this->assertStringContainsString('История сообщений этого разговора будет удалена.', $dialog);
        $this->assertStringContainsString('Это действие нельзя отменить.', $dialog);
        $this->assertStringContainsString('Без названия', $dialog);
        $this->assertStringContainsString('Отмена', $dialog);
        $this->assertStringContainsString('Удаление...', $dialog);
        $this->assertStringContainsString('bg-rose-500', $dialog);
        $this->assertStringContainsString('disabled={deleting}', $dialog);
    }

    public function test_successful_delete_updates_sidebar_and_leaves_the_deleted_id(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));

        $this->assertStringContainsString("method: 'DELETE'", $workspace);
        $this->assertStringContainsString('chats.destroy', $workspace);
        $this->assertStringContainsString('conversationItems.filter', $workspace);
        $this->assertStringContainsString("chats.show', next.id", $workspace);
        $this->assertStringContainsString('rememberConversations(remaining)', $workspace);
        $this->assertStringNotContainsString('window.location.reload', $workspace);
    }
}
