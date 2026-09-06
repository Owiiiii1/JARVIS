<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Services\ConversationIntelligence\TemporaryStyleExtractor;
use Tests\TestCase;

class TemporaryStyleExtractorTest extends TestCase
{
    public function test_latest_explicit_style_request_wins(): void
    {
        $extractor = new TemporaryStyleExtractor;

        $this->assertSame('short', $extractor->extract([
            'Создай задачу',
            'отвечай сейчас максимально коротко',
        ]));
        $this->assertSame('detailed', $extractor->fromText('говори подробнее'));
        $this->assertSame('italian', $extractor->fromText('отвечай по-итальянски'));
        $this->assertNull($extractor->fromText('Создай задачу купить фильтр'));
    }
}
