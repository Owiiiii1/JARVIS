<?php

namespace Tests\Unit\Reminders;

use Tests\TestCase;

class ReminderRoutesTest extends TestCase
{
    public function test_owner_and_user_workspace_expose_the_same_reminder_panel_routes(): void
    {
        $this->assertSame('/jarvis/reminders', route('jarvis.reminders.index', absolute: false));
        $this->assertSame('/chat/reminders', route('chat.reminders.index', absolute: false));
        $this->assertSame('/jarvis/reminders/9/cancel', route('jarvis.reminders.cancel', ['reminder' => 9], absolute: false));
        $this->assertSame('/chat/reminders/9/cancel', route('chat.reminders.cancel', ['reminder' => 9], absolute: false));
    }

    public function test_header_entry_is_capability_gated_and_not_telegram_gated(): void
    {
        $workspace = file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $panel = file_get_contents(base_path('resources/js/personal-workspace/RemindersPanel.jsx'));

        $this->assertStringContainsString('capabilities.reminders', $workspace);
        $this->assertStringContainsString('Напоминания', $workspace);
        $this->assertStringContainsString('aria-label="Напоминания"', $workspace);
        $this->assertStringNotContainsString('telegram_connected && capabilities.reminders', $workspace);
        $this->assertStringNotContainsString('Доставка сейчас только в Telegram', $panel);
        $this->assertStringContainsString('Напоминание сохранено в Jarvis', $panel);
    }
}
