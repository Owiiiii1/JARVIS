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
        $this->assertSame('/jarvis/reminders/9', route('jarvis.reminders.update', ['reminder' => 9], absolute: false));
        $this->assertSame('/chat/reminders/9/snooze', route('chat.reminders.snooze', ['reminder' => 9], absolute: false));
        $this->assertSame('/jarvis/reminders/9/complete', route('jarvis.reminders.complete', ['reminder' => 9], absolute: false));
        $this->assertSame('/chat/reminders/push', route('chat.reminders.push.store', absolute: false));
    }

    public function test_header_entry_is_capability_gated_and_not_telegram_gated(): void
    {
        $workspace = file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $panel = file_get_contents(base_path('resources/js/personal-workspace/RemindersPanel.jsx'));

        $this->assertStringContainsString('capabilities.reminders', $workspace);
        $this->assertStringContainsString('Напоминания', $workspace);
        $this->assertStringContainsString('aria-label="Напоминания"', $workspace);
        $this->assertStringContainsString('open-reminder', $workspace);
        $this->assertStringNotContainsString('telegram_connected && capabilities.reminders', $workspace);
        $this->assertStringNotContainsString('Доставка сейчас только в Telegram', $panel);
        $this->assertStringContainsString('Напоминание сохранено в Jarvis', $panel);
        $this->assertStringContainsString('Включить уведомления', $panel);
        $this->assertStringContainsString('Готово', $panel);
        $this->assertStringContainsString('Отменить', $panel);
        $this->assertStringContainsString('Due', $panel);
        $this->assertStringContainsString('Сегодня', $panel);
        $this->assertStringContainsString('Предстоящие', $panel);
        $this->assertStringContainsString('История', $panel);
        $this->assertStringNotContainsString('Recurrence пока не поддерживается', $panel);
    }

    public function test_service_worker_is_public_and_allowlists_open_urls(): void
    {
        $sw = file_get_contents(base_path('public/reminder-sw.js'));

        $this->assertStringContainsString("self.addEventListener('push'", $sw);
        $this->assertStringContainsString("self.addEventListener('notificationclick'", $sw);
        $this->assertStringContainsString("path.startsWith('/jarvis/')", $sw);
        $this->assertStringContainsString('clients.openWindow', $sw);
        $this->assertStringNotContainsString('event.notification.data.url', substr($sw, (int) strpos($sw, 'openWindow')));
    }
}
