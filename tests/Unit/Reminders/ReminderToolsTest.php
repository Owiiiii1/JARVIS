<?php

namespace Tests\Unit\Reminders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Reminders\ReminderService;
use App\Services\Tools\CancelReminderTool;
use App\Services\Tools\CompleteReminderTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ListRemindersTool;
use App\Services\Tools\ReminderToolResolver;
use App\Services\Tools\SnoozeReminderTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\UpdateReminderTool;
use Tests\TestCase;

class ReminderToolsTest extends TestCase
{
    public function test_create_definition_accepts_recurrence(): void
    {
        $definition = (new CreateReminderTool(new ReminderService))->definition();

        $this->assertArrayHasKey('recurrence', $definition->parameters['properties']);
        $this->assertStringContainsString('daily', $definition->description);
    }

    public function test_create_rejects_unknown_recurrence_without_persisting(): void
    {
        $result = (new CreateReminderTool(new ReminderService))->execute(
            new ToolCall('c1', CreateReminderTool::NAME, [
                'text' => 'every morning',
                'run_at_local' => '2026-09-07T10:00:00+02:00',
                'recurrence' => 'FREQ=DAILY',
            ]),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_recurrence', $result->payload['error']);
    }

    public function test_mutation_tools_require_id_or_query_and_do_not_guess(): void
    {
        $reminders = new ReminderService;
        $resolver = new ReminderToolResolver($reminders);
        $context = $this->context();
        $call = new ToolCall('c1', 'update_reminder', []);

        $update = (new UpdateReminderTool($reminders, $resolver))->execute($call, $context);
        $snooze = (new SnoozeReminderTool($reminders, $resolver))->execute($call, $context);
        $done = (new CompleteReminderTool($reminders, $resolver))->execute($call, $context);
        $cancel = (new CancelReminderTool($reminders, $resolver))->execute($call, $context);

        $this->assertSame('ambiguous', $update->payload['error']);
        $this->assertSame('ambiguous', $snooze->payload['error']);
        $this->assertSame('ambiguous', $done->payload['error']);
        $this->assertSame('ambiguous', $cancel->payload['error']);
    }

    public function test_list_tool_is_read_only_by_name(): void
    {
        $this->assertSame('list_reminders', (new ListRemindersTool(new ReminderService))->name());
        $this->assertSame('snooze_reminder', (new SnoozeReminderTool(new ReminderService, new ReminderToolResolver(new ReminderService)))->name());
        $this->assertSame('complete_reminder', (new CompleteReminderTool(new ReminderService, new ReminderToolResolver(new ReminderService)))->name());
        $this->assertSame('cancel_reminder', (new CancelReminderTool(new ReminderService, new ReminderToolResolver(new ReminderService)))->name());
        $this->assertSame('update_reminder', (new UpdateReminderTool(new ReminderService, new ReminderToolResolver(new ReminderService)))->name());
    }

    public function test_success_payload_includes_recurrence_and_push_flag(): void
    {
        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => 21,
            'text' => 'кофе',
            'recurrence_rule' => 'weekdays',
        ]);

        $payload = (new CreateReminderTool(new ReminderService))->successPayload(
            $reminder,
            '2026-09-07T08:00:00+02:00',
            'Europe/Rome',
            false,
            false,
            true,
        );

        $this->assertSame('weekdays', $payload['recurrence']);
        $this->assertTrue($payload['web_push_available']);
        $this->assertSame('web_push', $payload['delivery']);
        $this->assertFalse($payload['telegram_connected']);
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
        $conversation->forceFill([
            'id' => 8,
            'user_id' => 3,
        ]);

        return new ToolExecutionContext($user, $conversation);
    }
}
