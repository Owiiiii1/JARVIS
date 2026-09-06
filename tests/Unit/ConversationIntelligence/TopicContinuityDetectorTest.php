<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\TopicContinuityMode;
use App\Services\ConversationIntelligence\TopicContinuityDetector;
use Tests\TestCase;

class TopicContinuityDetectorTest extends TestCase
{
    public function test_short_follow_up_continues_or_is_subtopic(): void
    {
        $detector = new TopicContinuityDetector;
        $prior = ['Что там с проектом YFS?', 'Голосовой пайплайн на месте.'];

        $this->assertSame(TopicContinuityMode::Subtopic, $detector->detect('а по голосу?', $prior, ['YFS'], 'YFS'));
        $this->assertSame(TopicContinuityMode::Subtopic, $detector->detect('И ещё добавь туда цифры за август.', $prior, ['отчёт'], 'отчёт'));
    }

    public function test_return_phrase_recovers_previous_topic(): void
    {
        $detector = new TopicContinuityDetector;
        $mode = $detector->detect(
            'Вернёмся к YFS, что там с голосом?',
            ['Купи фильтр для станка', 'Задача создана.'],
            ['YFS', 'фильтр'],
            'фильтр',
        );

        $this->assertSame(TopicContinuityMode::Return, $mode);
        $this->assertSame('YFS', $detector->returnedTopicName('Вернёмся к YFS, что там с голосом?', ['YFS', 'фильтр'], 'фильтр'));
    }

    public function test_unrelated_long_utterance_is_a_topic_switch(): void
    {
        $detector = new TopicContinuityDetector;
        $mode = $detector->detect(
            'Давай отдельно разберём поставку станков на склад в Милане',
            ['Что там с проектом YFS?', 'Голосовой пайплайн на месте.'],
            ['YFS'],
            'YFS',
        );

        $this->assertSame(TopicContinuityMode::Switch, $mode);
    }
}
