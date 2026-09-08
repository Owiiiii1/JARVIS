<?php

namespace Tests\Unit\Conversations;

use App\Services\ConversationIntelligence\ConversationalPolicyPrompt;
use App\Services\Conversations\TurnIsolation;
use PHPUnit\Framework\TestCase;

class TurnIsolationTest extends TestCase
{
    public function test_presence_checks_are_detected(): void
    {
        $this->assertTrue(TurnIsolation::isPresenceCheck('эй'));
        $this->assertTrue(TurnIsolation::isPresenceCheck('ты тут?'));
        $this->assertTrue(TurnIsolation::isPresenceCheck('Hello'));
        $this->assertFalse(TurnIsolation::isPresenceCheck('посмотри программу и посчитай сдвиг оси A'));
    }

    public function test_repeat_requests_are_detected(): void
    {
        $this->assertTrue(TurnIsolation::isRepeatRequest('повтори предыдущий'));
        $this->assertTrue(TurnIsolation::isRepeatRequest('скажи ещё раз'));
        $this->assertFalse(TurnIsolation::isRepeatRequest('ты тут?'));
        $this->assertStringContainsString('new execution turn', implode("\n", ConversationalPolicyPrompt::lines()));
    }
}
