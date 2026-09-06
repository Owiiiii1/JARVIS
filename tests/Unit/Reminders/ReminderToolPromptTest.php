<?php

namespace Tests\Unit\Reminders;

use App\Services\Reminders\ReminderToolPrompt;
use PHPUnit\Framework\TestCase;

class ReminderToolPromptTest extends TestCase
{
    public function test_prompt_covers_multi_channel_and_mutation_tools(): void
    {
        $text = implode("\n", ReminderToolPrompt::lines());

        $this->assertStringContainsString('Jarvis Core reminder', $text);
        $this->assertStringContainsString('Telegram and Web Push are optional independent delivery adapters', $text);
        $this->assertStringContainsString('Web reminders panel', $text);
        $this->assertStringContainsString('list_reminders', $text);
        $this->assertStringContainsString('update_reminder', $text);
        $this->assertStringContainsString('snooze_reminder', $text);
        $this->assertStringContainsString('complete_reminder', $text);
        $this->assertStringContainsString('cancel_reminder', $text);
        $this->assertStringContainsString('Never update a random reminder', $text);
        $this->assertStringNotContainsString('telegram_not_connected', $text);
        $this->assertStringNotContainsString('Do not promise Web Push', $text);
        $this->assertStringNotContainsString('Recurring reminders are not supported yet', $text);
    }
}
