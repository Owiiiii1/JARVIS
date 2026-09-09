<?php

namespace Tests\Unit\Productivity;

use App\Enums\JarvisNotificationType;
use App\Enums\ProductivityBriefMode;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Productivity\ProactivePolicy;
use App\Services\Productivity\ProactiveTriggerDetector;
use App\Services\Productivity\ProductivityBriefCollector;
use App\Services\Productivity\ProductivityBriefRenderer;
use App\Services\Productivity\ProductivityBriefService;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Productivity\TaskDueDetector;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProductivityEngineTest extends TestCase
{
    public function test_due_detector_emits_overdue_once_per_task_key(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $task = $this->task(['due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC')]);
        $events = (new TaskDueDetector)->eventsFor($task, $now, 'Europe/Rome');

        $this->assertCount(1, $events);
        $this->assertSame(JarvisNotificationType::TaskOverdue, $events[0]['type']);
        $this->assertSame('task_overdue:1', $events[0]['dedupe_key']);
        $this->assertSame($events, (new TaskDueDetector)->eventsFor($task, $now, 'Europe/Rome'));
    }

    public function test_due_today_uses_local_date_in_dedupe(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        $task = $this->task(['due_at' => CarbonImmutable::parse('2026-09-06 18:00:00', 'UTC')]);
        $events = (new TaskDueDetector)->eventsFor($task, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'), 'Europe/Rome');

        $this->assertSame(JarvisNotificationType::TaskDue, $events[0]['type']);
        $this->assertSame('task_due:1:2026-09-06', $events[0]['dedupe_key']);
    }

    public function test_completed_task_does_not_emit(): void
    {
        $task = $this->task([
            'status' => TaskStatus::Completed,
            'due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC'),
        ]);

        $this->assertSame([], (new TaskDueDetector)->eventsFor($task, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'), 'UTC'));
    }

    public function test_brief_is_source_grounded_and_does_not_leak_foreign_rows(): void
    {
        $owner = $this->user(UserRole::Owner, 1);
        $other = $this->user(UserRole::User, 9);
        $ownTask = $this->task(['title' => 'свой отчёт', 'due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC')]);
        $foreign = $this->task(['id' => 2, 'user_id' => 9, 'title' => 'чужая задача']);
        $project = new Project;
        $project->forceFill(['id' => 4, 'user_id' => 1, 'name' => 'JARVIS', 'status' => 'active']);
        $foreignProject = new Project;
        $foreignProject->forceFill(['id' => 5, 'user_id' => 9, 'name' => 'Secret', 'status' => 'active']);
        $sources = (new ProductivityBriefCollector)->collect(
            $owner,
            ProductivityBriefMode::Daily,
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            [$ownTask, $foreign],
            [],
            [['title' => 'Standup']],
            [$project, $foreignProject],
        );
        $text = (new ProductivityBriefRenderer)->deterministic($sources);

        $this->assertStringContainsString('свой отчёт', $text);
        $this->assertStringNotContainsString('чужая задача', $text);
        $this->assertStringContainsString('JARVIS', $text);
        $this->assertStringNotContainsString('Secret', $text);
        $this->assertStringContainsString('Standup', $text);
        $this->assertSame([], (new ProductivityBriefCollector)->collect(
            $other,
            ProductivityBriefMode::Daily,
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            [$ownTask],
            [],
            [['title' => 'Standup']],
            [$project],
        )->calendar);
    }

    public function test_ai_failure_falls_back_to_deterministic_brief(): void
    {
        $failing = new class implements SynthesizesProductivityBrief
        {
            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return null;
            }
        };
        $ok = new class implements SynthesizesProductivityBrief
        {
            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return 'Кратко: отчёт просрочен.';
            }
        };
        $user = $this->user();
        $task = $this->task(['due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC')]);
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $fallback = (new ProductivityBriefService(synthesizer: $failing))->compose($user, ProductivityBriefMode::Daily, $now, [$task], []);
        $phrased = (new ProductivityBriefService(synthesizer: $ok))->compose($user, ProductivityBriefMode::Daily, $now, [$task], []);

        $this->assertFalse($fallback['ai_used']);
        $this->assertStringContainsString('свой отчёт', $fallback['text']);
        $this->assertTrue($phrased['ai_used']);
        $this->assertSame('Кратко: отчёт просрочен.', $phrased['text']);
    }

    public function test_truncated_ai_phrasing_falls_back_to_deterministic_brief(): void
    {
        $truncated = new class implements SynthesizesProductivityBrief
        {
            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return 'Доброе утро. Сводка на сегодня,';
            }
        };
        $user = $this->user();
        $task = $this->task(['due_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC')]);
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $result = (new ProductivityBriefService(synthesizer: $truncated))->compose($user, ProductivityBriefMode::Daily, $now, [$task], []);

        $this->assertFalse($result['ai_used']);
        $this->assertStringContainsString('свой отчёт', $result['text']);
        $this->assertStringNotContainsString('Доброе утро. Сводка на сегодня,', $result['text']);
    }

    public function test_briefs_default_off_and_respect_local_time(): void
    {
        $settings = new UserProductivitySetting;
        $settings->forceFill([
            'daily_brief_enabled' => false,
            'daily_brief_local_time' => '08:00',
        ]);
        $user = $this->user();
        $service = new ProductivitySettingsService;
        $now = CarbonImmutable::parse('2026-09-06 08:05:00', 'Europe/Rome')->utc();

        $this->assertFalse($service->isDue($settings, 'daily', $user, $now));
        $settings->daily_brief_enabled = true;
        $this->assertTrue($service->isDue($settings, 'daily', $user, $now));
        $settings->last_daily_brief_at = $now;
        $this->assertFalse($service->isDue($settings, 'daily', $user, $now));
        $this->assertFalse($service->isDue($settings, 'daily', $user, CarbonImmutable::parse('2026-09-06 06:00:00', 'Europe/Rome')->utc()));
    }

    public function test_proactive_respects_disabled_cooldown_max_and_dedupe(): void
    {
        $policy = new ProactivePolicy;
        $settings = new UserProductivitySetting;
        $settings->forceFill(['proactive_enabled' => false]);
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');

        $this->assertFalse($policy->mayEmit($settings, 0, null, $now));
        $settings->proactive_enabled = true;
        $this->assertTrue($policy->mayEmit($settings, 0, null, $now));
        $this->assertFalse($policy->mayEmit($settings, 3, null, $now));
        $this->assertFalse($policy->mayEmit($settings, 0, $now->subHour(), $now));
        $this->assertTrue($policy->mayEmit($settings, 0, $now->subHours(5), $now));
        $this->assertFalse($policy->mayEmit($settings, 0, null, $now, true));
        $this->assertSame(3, $policy->maxPerDay());
        $this->assertSame(4, $policy->cooldownHours());
    }

    public function test_proactive_triggers_are_deterministic_from_the_task(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $overdue = $this->task(['priority' => TaskPriority::Urgent, 'due_at' => $now->subHour()]);
        $soon = $this->task(['id' => 2, 'priority' => TaskPriority::High, 'due_at' => $now->addHour()]);
        $detector = new ProactiveTriggerDetector;

        $this->assertSame('task_overdue', $detector->forTask($overdue, $now)[0]['trigger']);
        $this->assertSame('proactive:task_overdue:1', $detector->forTask($overdue, $now)[0]['dedupe_key']);
        $this->assertSame('task_approaching', $detector->forTask($soon, $now)[0]['trigger']);
        $this->assertSame([], $detector->forTask($this->task(['due_at' => null]), $now));
    }

    public function test_evening_and_weekly_modes_use_completed_window(): void
    {
        $user = $this->user();
        $done = $this->task([
            'id' => 3,
            'title' => 'закрыл отчёт',
            'status' => TaskStatus::Completed,
            'completed_at' => CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'),
        ]);
        $evening = (new ProductivityBriefCollector)->collect(
            $user,
            ProductivityBriefMode::Evening,
            CarbonImmutable::parse('2026-09-06 20:00:00', 'UTC'),
            [$done],
            [],
        );

        $this->assertSame('закрыл отчёт', $evening->tasksCompleted[0]['title']);
    }

    private function user(UserRole $role = UserRole::User, int $id = 1): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'role' => $role,
            'status' => UserStatus::Active,
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
            'title' => 'свой отчёт',
            'status' => TaskStatus::Open,
            'priority' => TaskPriority::Normal,
            'timezone' => 'Europe/Rome',
        ], $attributes));

        return $task;
    }
}
