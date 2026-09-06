<?php

namespace Tests\Unit\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Tasks\TaskSelection;
use App\Services\Tasks\TaskService;
use App\Services\Tools\CancelTaskTool;
use App\Services\Tools\CompleteTaskTool;
use App\Services\Tools\CreateSubtaskTool;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\GetTaskTool;
use App\Services\Tools\LinkTaskReminderTool;
use App\Services\Tools\ListTasksTool;
use App\Services\Tools\StartTaskTool;
use App\Services\Tools\TaskToolResolver;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\UpdateTaskTool;
use Tests\TestCase;

class TaskSelectionAndToolsTest extends TestCase
{
    public function test_selection_does_not_guess_among_several_matches(): void
    {
        $one = $this->task(1, 'отчёт клиенту');
        $two = $this->task(2, 'отчёт внутренний');
        $resolved = TaskSelection::resolve(null, 'отчёт', [$one, $two]);

        $this->assertFalse($resolved['ok']);
        $this->assertSame('ambiguous', $resolved['error']);
        $this->assertCount(2, $resolved['candidates']);
    }

    public function test_selection_unique_query_resolves(): void
    {
        $resolved = TaskSelection::resolve(null, 'клиенту', [
            $this->task(1, 'отчёт клиенту'),
            $this->task(2, 'купить молоко'),
        ]);

        $this->assertTrue($resolved['ok']);
        $this->assertSame(1, $resolved['task']->id);
    }

    public function test_foreign_id_is_not_found(): void
    {
        $resolved = TaskSelection::resolve(99, null, [$this->task(1, 'отчёт')]);
        $this->assertSame('not_found', $resolved['error']);
    }

    public function test_create_tool_rejects_empty_title_without_persisting(): void
    {
        $result = (new CreateTaskTool(new TaskService))->execute(
            new ToolCall('c1', CreateTaskTool::NAME, ['title' => '  ']),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->payload['error']);
    }

    public function test_mutation_tools_require_id_or_query(): void
    {
        $tasks = new TaskService;
        $resolver = new TaskToolResolver($tasks);
        $call = new ToolCall('c1', 'update_task', []);
        $context = $this->context();

        $this->assertSame('ambiguous', (new UpdateTaskTool($tasks, $resolver))->execute($call, $context)->payload['error']);
        $this->assertSame('ambiguous', (new StartTaskTool($tasks, $resolver))->execute($call, $context)->payload['error']);
        $this->assertSame('ambiguous', (new CompleteTaskTool($tasks, $resolver))->execute($call, $context)->payload['error']);
        $this->assertSame('ambiguous', (new CancelTaskTool($tasks, $resolver))->execute($call, $context)->payload['error']);
        $this->assertSame('ambiguous', (new CreateSubtaskTool($tasks, $resolver))->execute(new ToolCall('c1', 'create_subtask', ['title' => 'часть']), $context)->payload['error']);
        $this->assertSame('ambiguous', (new GetTaskTool($tasks, $resolver))->execute($call, $context)->payload['error']);
    }

    public function test_tool_names_and_create_policy_text(): void
    {
        $this->assertSame('create_task', (new CreateTaskTool(new TaskService))->name());
        $this->assertSame('list_tasks', (new ListTasksTool(new TaskService))->name());
        $this->assertSame('link_task_reminder', LinkTaskReminderTool::NAME);
        $this->assertStringContainsString('надо бы', (new CreateTaskTool(new TaskService))->definition()->description);
        $this->assertStringContainsString('commitment', (new CreateTaskTool(new TaskService))->definition()->description);
    }

    public function test_success_payload_shape(): void
    {
        $task = $this->task(8, 'предложение');
        $payload = (new CreateTaskTool(new TaskService))->successPayload($task);

        $this->assertTrue($payload['success']);
        $this->assertSame(8, $payload['task_id']);
        $this->assertSame('open', $payload['status']);
        $this->assertSame('normal', $payload['priority']);
    }

    private function task(int $id, string $title): Task
    {
        $task = new Task;
        $task->forceFill([
            'id' => $id,
            'user_id' => 3,
            'title' => $title,
            'status' => TaskStatus::Open,
            'priority' => TaskPriority::Normal,
        ]);

        return $task;
    }

    private function context(): ToolExecutionContext
    {
        $user = new User;
        $user->forceFill([
            'id' => 3,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);
        $conversation = new Conversation;
        $conversation->forceFill(['id' => 8, 'user_id' => 3]);

        return new ToolExecutionContext($user, $conversation);
    }
}
