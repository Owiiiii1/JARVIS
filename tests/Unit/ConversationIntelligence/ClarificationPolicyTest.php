<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\ReferenceOutcome;
use App\Enums\ToolOperationClass;
use App\Enums\TopicContinuityMode;
use App\Services\ConversationIntelligence\ClarificationPolicy;
use App\Services\ConversationIntelligence\WorkingContext;
use Tests\TestCase;

class ClarificationPolicyTest extends TestCase
{
    public function test_read_only_does_not_clarify_a_resolved_pronoun(): void
    {
        $policy = new ClarificationPolicy;
        $working = new WorkingContext(
            topicMode: TopicContinuityMode::Continue,
            referenceOutcome: ReferenceOutcome::Resolved,
            continuitySource: 'recent_tail',
        );

        $this->assertNull($policy->reason($working, ToolOperationClass::Read));
        $this->assertFalse($policy->shouldClarifyRelativeDate('Покажи встречи завтра'));
    }

    public function test_write_clarifies_when_the_target_is_ambiguous(): void
    {
        $policy = new ClarificationPolicy;
        $working = new WorkingContext(
            topicMode: TopicContinuityMode::Continue,
            referenceOutcome: ReferenceOutcome::Ambiguous,
            continuitySource: 'recent_tail',
        );

        $this->assertSame('write_target_ambiguous', $policy->reason($working, ToolOperationClass::Write));
        $this->assertSame('destructive_ambiguous', $policy->reason($working, ToolOperationClass::Destructive));
    }
}
