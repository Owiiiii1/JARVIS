<?php

namespace Tests\Unit\Watchers;

use App\Services\Watchers\GmailWatcherQuery;
use PHPUnit\Framework\TestCase;

class GmailWatcherQueryTest extends TestCase
{
    public function test_compiles_a_sender_email_filter(): void
    {
        $source = GmailWatcherQuery::normalize(['sender' => 'marco@example.com']);

        $this->assertSame(['marco@example.com'], $source['senders']);
        $this->assertSame('from:marco@example.com', GmailWatcherQuery::compile($source));
    }

    public function test_compiles_multiple_domains_as_or_filter(): void
    {
        $source = GmailWatcherQuery::normalize([
            'sender_domains' => ['marcellinequadronno.it', 'accademiaucraina.it'],
        ]);

        $this->assertSame(
            '(from:marcellinequadronno.it OR from:accademiaucraina.it)',
            GmailWatcherQuery::compile($source),
        );
        $this->assertSame(
            'marcellinequadronno.it и accademiaucraina.it',
            GmailWatcherQuery::humanList($source),
        );
    }

    public function test_extracts_an_email_without_promoting_its_host_to_a_domain_filter(): void
    {
        $extracted = GmailWatcherQuery::extractFromText('Сообщи, когда придёт письмо от marco@example.com');

        $this->assertSame(['marco@example.com'], $extracted['senders']);
        $this->assertSame([], $extracted['sender_domains']);
    }

    public function test_extracts_domains_from_inbound_text(): void
    {
        $extracted = GmailWatcherQuery::extractFromText(
            'Следи за письмами от @marcellinequadronno.it и accademiaucraina.it',
        );

        $this->assertSame(['marcellinequadronno.it', 'accademiaucraina.it'], $extracted['sender_domains']);
    }

    public function test_merges_an_additional_domain(): void
    {
        $merged = GmailWatcherQuery::merge(
            ['sender_domains' => ['marcellinequadronno.it']],
            ['sender_domains' => ['accademiaucraina.it']],
        );

        $this->assertSame(['marcellinequadronno.it', 'accademiaucraina.it'], $merged['sender_domains']);
    }

    public function test_unrelated_sender_does_not_match(): void
    {
        $source = GmailWatcherQuery::normalize(['sender_domains' => ['school.it']]);

        $this->assertTrue(GmailWatcherQuery::messageMatches($source, 'Tutor <tutor@school.it>', 'Hello'));
        $this->assertFalse(GmailWatcherQuery::messageMatches($source, 'Stripe <no-reply@stripe.com>', 'Receipt'));
    }
}
