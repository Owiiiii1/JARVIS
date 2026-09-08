<?php

namespace Tests\Unit\Watchers;

use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherDigestFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WatcherDigestFormatterTest extends TestCase
{
    public function test_zero_mail_uses_the_morning_empty_state(): void
    {
        $this->assertSame('С утра новых писем нет.', (new WatcherDigestFormatter)->format([], true));
        $this->assertSame('Новых писем нет.', (new WatcherDigestFormatter)->format([], false));
    }

    public function test_digest_highlights_people_and_groups_noise(): void
    {
        $now = CarbonImmutable::parse('2026-09-08 06:00:00', 'UTC');
        $text = (new WatcherDigestFormatter)->format([
            $this->observation('1', 'Marco Rossi <marco@yfs.test>', 'New YFS design'),
            $this->observation('2', 'DentalPro <hello@dentalpro.test>', 'Meeting Thursday confirmed'),
            $this->observation('3', 'GitHub <notifications@github.com>', 'dependabot alert'),
            $this->observation('4', 'Promo <noreply@ads.test>', '50% off'),
        ], true);

        $this->assertStringContainsString('За ночь пришло 4 новых письма.', $text);
        $this->assertStringContainsString('Marco Rossi', $text);
        $this->assertStringContainsString('DentalPro', $text);
        $this->assertStringContainsString('рекламных или технических', $text);
        $this->assertStringNotContainsString('gmail_message', $text);
        $this->assertStringNotContainsString('thread_id', $text);
        unset($now);
    }

    private function observation(string $id, string $sender, string $subject): WatcherObservation
    {
        return new WatcherObservation(
            sourceType: 'gmail',
            sourceId: $id,
            eventType: 'email_received',
            fingerprint: $id,
            occurredAt: CarbonImmutable::parse('2026-09-08 01:00:00', 'UTC'),
            title: $subject,
            metadata: ['sender' => $sender, 'subject' => $subject],
        );
    }
}
