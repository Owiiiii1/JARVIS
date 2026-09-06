<?php

namespace Tests\Unit\Tasks;

use App\Enums\ProjectStatus;
use App\Enums\ReminderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TaskLifecycleTest extends TestCase
{
    public function test_regular_user_has_tasks_capability_without_projects(): void
    {
        $user = $this->user(UserRole::User);

        $this->assertTrue($user->canUseCapability(UserCapability::TASKS));
        $this->assertFalse($user->canUseCapability(UserCapability::PROJECTS));
        $this->assertTrue($this->user(UserRole::Owner)->canUseCapability(UserCapability::TASKS));
        $this->assertTrue($this->user(UserRole::Owner)->canUseCapability(UserCapability::PROJECTS));
    }

    public function test_create_validation_rejects_empty_title(): void
    {
        try {
            (new TaskService)->validateCreate($this->user(UserRole::User), '  ');
            $this->fail('Expected TaskException.');
        } catch (TaskException $exception) {
            $this->assertSame('empty_title', $exception->error);
        }
    }

    public function test_inactive_user_cannot_create(): void
    {
        try {
            (new TaskService)->validateCreate($this->user(UserRole::User, UserStatus::Disabled), 'отчёт');
            $this->fail('Expected TaskException.');
        } catch (TaskException $exception) {
            $this->assertSame('user_inactive', $exception->error);
        }
    }

    public function test_start_complete_and_cancel_are_distinct(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $task = $this->task();
        TaskLifecycle::markStarted($task);
        $this->assertSame(TaskStatus::InProgress, $task->status);

        TaskLifecycle::markCompleted($task, $now);
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);

        $open = $this->task(['id' => 2]);
        TaskLifecycle::markCancelled($open, $now);
        $this->assertSame(TaskStatus::Cancelled, $open->status);
        $this->assertNull($open->completed_at);
        $this->assertNotNull($open->cancelled_at);
    }

    public function test_complete_parent_with_open_subtasks_requires_force(): void
    {
        $parent = $this->task();
        $child = $this->task(['id' => 2, 'parent_task_id' => 1, 'title' => 'черновик']);

        try {
            TaskLifecycle::markCompleted($parent, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'), [$child]);
            $this->fail('Expected open_subtasks.');
        } catch (TaskException $exception) {
            $this->assertSame('open_subtasks', $exception->error);
            $this->assertSame(TaskStatus::Open, $parent->status);
            $this->assertSame(TaskStatus::Open, $child->status);
        }

        TaskLifecycle::markCompleted($parent, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'), [$child], [], true);
        $this->assertSame(TaskStatus::Completed, $parent->status);
        $this->assertSame(TaskStatus::Open, $child->status);
    }

    public function test_complete_cancels_future_linked_reminders_and_keeps_history(): void
    {
        $task = $this->task();
        $open = $this->reminder(['id' => 21, 'status' => ReminderStatus::Scheduled]);
        $history = $this->reminder(['id' => 22, 'status' => ReminderStatus::Delivered, 'text' => 'уже было']);

        $cancelled = TaskLifecycle::markCompleted(
            $task,
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            [],
            [$open, $history],
        );

        $this->assertCount(1, $cancelled);
        $this->assertSame(ReminderStatus::Cancelled, $open->status);
        $this->assertNotNull($open->cancelled_at);
        $this->assertSame(ReminderStatus::Delivered, $history->status);
        $this->assertSame(21, $open->id);
        $this->assertSame(22, $history->id);
    }

    public function test_unrelated_reminder_is_untouched(): void
    {
        $lone = $this->reminder(['id' => 30, 'task_id' => null]);
        TaskLifecycle::cancelFutureLinkedReminders([], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
        $this->assertSame(ReminderStatus::Scheduled, $lone->status);
    }

    public function test_priorities_and_due_flags(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $overdue = $this->task(['due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC')]);
        $today = $this->task(['id' => 2, 'due_at' => CarbonImmutable::parse('2026-09-06 18:00:00', 'UTC')]);
        $user = $this->user(UserRole::User);
        $service = new TaskService;

        $overdueRow = $service->serializeForPanel($overdue, 'Europe/Rome', $user, false, $now);
        $todayRow = $service->serializeForPanel($today, 'Europe/Rome', $user, false, $now);

        $this->assertTrue($overdueRow['is_overdue']);
        $this->assertFalse($overdueRow['is_due_today']);
        $this->assertTrue($todayRow['is_due_today']);
        $this->assertFalse($todayRow['is_overdue']);
        $this->assertSame('urgent', $this->task(['priority' => TaskPriority::Urgent])->priority->value);
    }

    public function test_project_payload_is_hidden_from_ordinary_user(): void
    {
        $user = $this->user(UserRole::User);
        $task = $this->task();
        $project = new Project;
        $project->forceFill(['id' => 4, 'user_id' => 1, 'name' => 'JARVIS', 'status' => ProjectStatus::Active]);
        $task->setRelation('project', $project);
        $row = (new TaskService)->serializeForPanel(
            $task,
            'Europe/Rome',
            $user,
            false,
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
        );

        $this->assertNull($row['project']);
    }

    public function test_reopen_only_from_completed(): void
    {
        $task = $this->task(['status' => TaskStatus::Completed, 'completed_at' => CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC')]);
        TaskLifecycle::markReopened($task);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->completed_at);

        try {
            TaskLifecycle::markReopened($this->task(['status' => TaskStatus::Cancelled]));
            $this->fail('Expected not_reopenable.');
        } catch (TaskException $exception) {
            $this->assertSame('not_reopenable', $exception->error);
        }
    }

    public function test_cycle_detection_same_id(): void
    {
        $task = $this->task();
        $this->assertTrue(TaskLifecycle::wouldCreateCycle($task, $task));
    }

    private function user(UserRole $role, UserStatus $status = UserStatus::Active, int $id = 1): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => 'Test',
            'email' => 'user'.$id.'@example.test',
            'role' => $role,
            'status' => $status,
            'timezone' => 'Europe/Rome',
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        $task = new Task;
        $task->forceFill(array_merge([
            'id' => 1,
            'user_id' => 1,
            'title' => 'отправить отчёт',
            'status' => TaskStatus::Open,
            'priority' => TaskPriority::Normal,
            'timezone' => 'Europe/Rome',
        ], $attributes));
        $task->setRelation('reminders', collect());
        $task->setRelation('subtasks', collect());

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reminder(array $attributes = []): Reminder
    {
        $reminder = new Reminder;
        $reminder->forceFill(array_merge([
            'id' => 10,
            'user_id' => 1,
            'task_id' => 1,
            'text' => 'отчёт',
            'run_at' => CarbonImmutable::parse('2026-09-07 08:00:00', 'UTC'),
            'timezone' => 'Europe/Rome',
            'status' => ReminderStatus::Scheduled,
            'metadata' => ['attempts' => 0],
        ], $attributes));

        return $reminder;
    }
}
