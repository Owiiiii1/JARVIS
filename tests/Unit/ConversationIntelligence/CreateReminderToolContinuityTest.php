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
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ToolExecutionContext;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class CreateReminderToolContinuityTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_pronoun_reminder_links_the_trusted_recent_task(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $task = Task::query()->create([
                'user_id' => $user->id,
                'title' => 'купить фильтр для станка',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
            ]);
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Напомни про неё завтра утром',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $working = new WorkingContext(
                topicMode: TopicContinuityMode::Continue,
                referenceOutcome: ReferenceOutcome::Resolved,
                continuitySource: 'recent_tool_results',
                recentToolReferences: [new ConversationalEntity('task', 'купить фильтр для станка', (int) $task->id, true)],
            );
            $result = app(CreateReminderTool::class)->execute(
                new ToolCall('c1', CreateReminderTool::NAME, [
                    'text' => 'купить фильтр для станка',
                    'run_at_local' => now()->timezone('Europe/Rome')->addDay()->setTime(9, 0)->format('Y-m-d\\TH:i:sP'),
                ]),
                new ToolExecutionContext($user, $conversation, $inbound, working: $working),
            );

            $this->assertTrue($result->success);
            $this->assertSame((int) $task->id, $result->payload['task_id']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
