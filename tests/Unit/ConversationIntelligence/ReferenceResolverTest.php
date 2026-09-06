<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\ReferenceOutcome;
use App\Services\ConversationIntelligence\ConversationalEntity;
use App\Services\ConversationIntelligence\ReferenceResolver;
use Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    public function test_pronoun_resolves_to_unique_recent_task(): void
    {
        $resolver = new ReferenceResolver;
        $task = new ConversationalEntity('task', 'отправить отчёт', 41, true);
        $result = $resolver->resolve('Напомни про неё завтра', [$task], $task);

        $this->assertSame(ReferenceOutcome::Resolved, $result['outcome']);
        $this->assertSame(41, $result['entity']?->id);
    }

    public function test_ambiguous_when_two_tasks_match_a_pronoun(): void
    {
        $resolver = new ReferenceResolver;
        $one = new ConversationalEntity('task', 'отчёт клиенту', 1, true);
        $two = new ConversationalEntity('task', 'отчёт внутренний', 2, true);
        $result = $resolver->resolve('закрой её', [$one, $two], null);

        $this->assertSame(ReferenceOutcome::Ambiguous, $result['outcome']);
        $this->assertTrue($result['unresolved']);
    }

    public function test_project_pronoun_keeps_named_project(): void
    {
        $resolver = new ReferenceResolver;
        $project = new ConversationalEntity('project', 'YFS', 9, true);
        $result = $resolver->resolve('что у него по голосу?', [$project], $project);

        $this->assertSame(ReferenceOutcome::Resolved, $result['outcome']);
        $this->assertSame('YFS', $result['entity']?->label);
    }

    public function test_incomplete_phrase_resolves_when_context_is_unique(): void
    {
        $resolver = new ReferenceResolver;
        $task = new ConversationalEntity('task', 'отправить отчёт', 7, true);
        $this->assertTrue($resolver->looksIncomplete('а если его завтра?'));
        $result = $resolver->resolve('а если его завтра?', [$task], $task, true);

        $this->assertSame(ReferenceOutcome::IncompleteResolved, $result['outcome']);
        $this->assertSame(7, $result['entity']?->id);
    }

    public function test_expired_tool_reference_is_not_reused(): void
    {
        $resolver = new ReferenceResolver;
        $expired = new ConversationalEntity('task', 'старая задача', 3, true, true);
        $result = $resolver->resolve('напомни про неё', [$expired], $expired);

        $this->assertSame(ReferenceOutcome::Unresolved, $result['outcome']);
    }
}
