<?php

namespace Tests\Unit\Watchers;

use App\Services\Watchers\ProactiveCheckIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProactiveCheckIntentTest extends TestCase
{
    #[DataProvider('watcherPhrases')]
    public function test_jarvis_mail_checks_are_not_self_reminders(string $text): void
    {
        $this->assertTrue(ProactiveCheckIntent::jarvisShouldMonitorMail($text));
        $this->assertFalse(ProactiveCheckIntent::userSelfReminder($text));
    }

    #[DataProvider('reminderPhrases')]
    public function test_user_self_mail_reminders_stay_reminders(string $text): void
    {
        $this->assertTrue(ProactiveCheckIntent::userSelfReminder($text));
        $this->assertFalse(ProactiveCheckIntent::jarvisShouldMonitorMail($text));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function watcherPhrases(): array
    {
        return [
            'morning_check' => ['Проверяй каждое утро почту и сообщай мне, что нового пришло.'],
            'look_at_new' => ['Каждое утро посмотри новые письма.'],
            'watch_and_digest' => ['Следи за почтой и утром присылай сводку.'],
            'daily_eight' => ['Каждый день в 8 проверяй Gmail.'],
            'report_new' => ['Сообщай мне по утрам, какие новые письма пришли.'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reminderPhrases(): array
    {
        return [
            'remind_morning' => ['Напомни мне проверить почту утром.'],
            'remind_tomorrow' => ['Напомни завтра посмотреть Gmail.'],
        ];
    }
}
