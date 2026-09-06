<?php

namespace Tests\Unit\ConversationIntelligence;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ReferenceOutcome;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TopicContinuityMode;
use App\Models\Message;
use App\Models\Task;
use App\Services\Ai\DTO\ToolCall;
use App\Services\ConversationIntelligence\ConversationalEntity;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\Conversations\ConversationService;
use App\Services\Tasks\TaskService;
use App\Services\Tools\TaskToolResolver;
use App\Services\Tools\ToolExecutionContext;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class TaskToolResolverContinuityTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_pronominal_reminder_uses_unique_trusted_recent_task(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $task = Task::query()->create([
                'user_id' => $user->id,
                'title' => 'отправить отчёт',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
            ]);
            $working = new WorkingContext(
                topicMode: TopicContinuityMode::Continue,
                referenceOutcome: ReferenceOutcome::Resolved,
                continuitySource: 'recent_tool_results',
                recentToolReferences: [new ConversationalEntity('task', 'отправить отчёт', (int) $task->id, true)],
                lastImportantObject: new ConversationalEntity('task', 'отправить отчёт', (int) $task->id, true),
            );
            $resolved = (new TaskToolResolver(new TaskService))->resolve(
                new ToolCall('c1', 'link_task_reminder', []),
                $this->context($user, $conversation, 'Напомни про неё завтра', $working),
                'link_task_reminder',
            );

            $this->assertIsArray($resolved);
            $this->assertTrue($resolved['ok']);
            $this->assertSame((int) $task->id, $resolved['task']->id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_specific_query_does_not_guess_among_similar_tasks(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            Task::query()->create([
                'user_id' => $user->id,
                'title' => 'отчёт клиенту',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
            ]);
            Task::query()->create([
                'user_id' => $user->id,
                'title' => 'отчёт внутренний',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
            ]);
            $result = (new TaskToolResolver(new TaskService))->resolve(
                new ToolCall('c1', 'complete_task', ['query' => 'отчёт']),
                $this->context($user, $conversation, 'закрой задачу про отчёт', new WorkingContext(
                    topicMode: TopicContinuityMode::Continue,
                    referenceOutcome: ReferenceOutcome::None,
                    continuitySource: 'recent_tail',
                )),
                'complete_task',
            );

            $this->assertFalse($result->success);
            $this->assertSame('ambiguous', $result->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_expired_trusted_task_is_not_used_for_mutation(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $result = (new TaskToolResolver(new TaskService))->resolve(
                new ToolCall('c1', 'complete_task', []),
                $this->context($user, $conversation, 'закрой её', new WorkingContext(
                    topicMode: TopicContinuityMode::Switch,
                    referenceOutcome: ReferenceOutcome::Unresolved,
                    continuitySource: 'recent_tool_results',
                    recentToolReferences: [new ConversationalEntity('task', 'старая', 41, true, true)],
                )),
                'complete_task',
            );

            $this->assertFalse($result->success);
            $this->assertSame('ambiguous', $result->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_invented_task_id_is_rejected_on_a_pronoun_turn(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $task = Task::query()->create([
                'user_id' => $user->id,
                'title' => 'отправить отчёт',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
            ]);
            $result = (new TaskToolResolver(new TaskService))->resolve(
                new ToolCall('c1', 'complete_task', ['task_id' => 999999001]),
                $this->context($user, $conversation, 'закрой её', new WorkingContext(
                    topicMode: TopicContinuityMode::Continue,
                    referenceOutcome: ReferenceOutcome::Resolved,
                    continuitySource: 'recent_tool_results',
                    recentToolReferences: [new ConversationalEntity('task', 'отправить отчёт', (int) $task->id, true)],
                )),
                'complete_task',
            );

            $this->assertFalse($result->success);
            $this->assertSame('ambiguous', $result->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function context($user, $conversation, string $body, WorkingContext $working): ToolExecutionContext
    {
        $inbound = new Message;
        $inbound->forceFill([
            'id' => 15,
            'body' => $body,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'message_type' => MessageType::Text,
        ]);

        return new ToolExecutionContext($user, $conversation, $inbound, working: $working);
    }
}
