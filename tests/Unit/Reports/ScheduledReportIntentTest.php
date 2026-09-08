<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\ScheduledReportIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduledReportIntentTest extends TestCase
{
    #[DataProvider('reportPhrases')]
    public function test_periodic_plan_and_digest_phrases_are_scheduled_reports(string $text): void
    {
        $this->assertTrue(ScheduledReportIntent::matches($text));
    }

    #[DataProvider('nonReportPhrases')]
    public function test_reminders_and_gmail_events_are_not_scheduled_reports(string $text): void
    {
        $this->assertFalse(ScheduledReportIntent::matches($text));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reportPhrases(): array
    {
        return [
            'tomorrow_22' => ['каждый вечер в 22:00 присылай мне отчет о том какие планы на завтра. исходя из наших планов, календаря и связанного календаря. только не напоминание а отчет'],
            'today_830' => ['каждое утро в 8:30 отчет с планами на текущий день'],
            'mail_groups_9' => ['каждое утро в 9:00 отчет про новые письма и сводку по группам'],
            'morning_mail' => ['каждое утро дай сводку почты'],
            'check_mail' => ['Проверяй каждое утро почту и сообщай мне, что нового пришло.'],
            'daily_gmail' => ['Каждый день в 7:30 проверяй Gmail.'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonReportPhrases(): array
    {
        return [
            'self_reminder' => ['Напомни в 9 проверить почту'],
            'remind_me' => ['Напомни мне завтра проверить почту.'],
            'gmail_event' => ['жди письмо от школы'],
            'watch_sender' => ['Следи за письмами от @example.com'],
        ];
    }
}
