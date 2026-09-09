<?php

namespace Tests\Unit\Productivity;

use App\Services\Productivity\ProductivityBriefPhrasing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductivityBriefPhrasingTest extends TestCase
{
    #[DataProvider('incompletePhrasing')]
    public function test_rejects_truncated_or_empty_phrasing(string $phrased, ?string $finishReason): void
    {
        $this->assertFalse(ProductivityBriefPhrasing::isComplete($phrased, $finishReason));
    }

    #[DataProvider('completePhrasing')]
    public function test_accepts_finished_phrasing(string $phrased, ?string $finishReason): void
    {
        $this->assertTrue(ProductivityBriefPhrasing::isComplete($phrased, $finishReason));
    }

    public function test_rejects_a_greeting_fragment_when_the_deterministic_report_is_long(): void
    {
        $deterministic = str_repeat('Заполнить материалы для WOW Cleaning. ', 4);

        $this->assertFalse(ProductivityBriefPhrasing::isSubstantial($deterministic, 'Доброе утро. Сводка на сегодня.'));
        $this->assertTrue(ProductivityBriefPhrasing::isSubstantial($deterministic, 'Доброе утро. Сегодня: заполнить материалы для WOW Cleaning и сделать иконки.'));
        $this->assertTrue(ProductivityBriefPhrasing::isSubstantial('Короткий отчёт.', 'Кратко: отчёт просрочен.'));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function incompletePhrasing(): array
    {
        return [
            'trailing_comma' => ['Доброе утро. Сводка на сегодня,', null],
            'trailing_colon' => ['Планы на сегодня:', null],
            'max_tokens' => ['Доброе утро. Сводка на сегодня.', 'MAX_TOKENS'],
            'length' => ['Доброе утро. Сводка на сегодня.', 'length'],
            'empty' => ['  ', null],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function completePhrasing(): array
    {
        return [
            'period' => ['Доброе утро. Сегодня две задачи по WOW Cleaning.', 'stop'],
            'question' => ['Нужно ли перенести созвон?', 'STOP'],
            'null_reason' => ['Кратко: отчёт просрочен.', null],
        ];
    }
}
