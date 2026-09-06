<?php

namespace Tests\Unit\Reminders;

use App\Services\Reminders\ReminderToolPrompt;
use PHPUnit\Framework\TestCase;

class ReminderToolPromptTest extends TestCase
{
    public function test_prompt_does_not_require_telegram_to_create_a_reminder(): void
    {
        $text = implode("\n", ReminderToolPrompt::lines());

        $this->assertStringContainsString('Jarvis Core reminder', $text);
        $this->assertStringContainsString('Telegram is optional delivery', $text);
        $this->assertStringContainsString('Web reminders panel', $text);
        $this->assertStringNotContainsString('telegram_not_connected', $text);
        $this->assertStringNotContainsString('Для получения напоминаний сначала подключите Telegram.', $text);
        $this->assertStringContainsString('Do not promise Web Push', $text);
    }
}
