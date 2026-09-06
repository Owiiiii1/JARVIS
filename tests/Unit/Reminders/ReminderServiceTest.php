<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDeliveryState;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    public function test_user_without_telegram_can_pass_create_validation(): void
    {
        $this->travelTo('2026-09-06 09:00:00');
        $user = $this->user(UserRole::User);

        (new ReminderService)->validateCreate(
            $user,
            'проверить чайник',
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            'Europe/Rome',
        );

        $this->assertTrue($user->canUseCapability(UserCapability::REMINDERS));
    }

    public function test_owner_without_telegram_can_pass_create_validation(): void
    {
        $this->travelTo('2026-09-06 09:00:00');
        $user = $this->user(UserRole::Owner);

        (new ReminderService)->validateCreate(
            $user,
            'owner task',
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            'Europe/Rome',
        );

        $this->assertTrue($user->canUseCapability(UserCapability::REMINDERS));
    }

    public function test_create_rejects_inactive_user(): void
    {
        $user = $this->user(UserRole::User, UserStatus::Disabled);

        $this->expectException(ReminderException::class);
        $this->expectExceptionMessage('User is not active.');

        try {
            (new ReminderService)->validateCreate(
                $user,
                'task',
                CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
                'Europe/Rome',
            );
        } catch (ReminderException $exception) {
            $this->assertSame('user_inactive', $exception->error);
            throw $exception;
        }
    }

    public function test_create_rejects_empty_text(): void
    {
        try {
            (new ReminderService)->validateCreate(
                $this->user(UserRole::User),
                '   ',
                CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
                'Europe/Rome',
            );
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('empty_text', $exception->error);
        }
    }

    public function test_create_rejects_invalid_timezone(): void
    {
        try {
            (new ReminderService)->validateCreate(
                $this->user(UserRole::User),
                'task',
                CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
                'Not/AZone',
            );
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('invalid_timezone', $exception->error);
        }
    }

    public function test_create_rejects_past_time(): void
    {
        $this->travelTo('2026-09-06 12:00:00');

        try {
            (new ReminderService)->validateCreate(
                $this->user(UserRole::User),
                'task',
                CarbonImmutable::parse('2026-09-06 11:59:00', 'UTC'),
                'Europe/Rome',
            );
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('past_time', $exception->error);
        }
    }

    public function test_regular_user_has_reminders_capability_without_telegram(): void
    {
        $user = $this->user(UserRole::User);

        $this->assertTrue($user->canUseCapability(UserCapability::REMINDERS));
        $this->assertTrue($this->user(UserRole::Owner)->canUseCapability(UserCapability::REMINDERS));
    }

    public function test_panel_row_for_overdue_no_channel_is_due_and_not_failed(): void
    {
        $this->travelTo('2026-09-06 12:00:00');

        $reminder = $this->reminder([
            'user_id' => 7,
            'text' => 'проверить чайник',
            'status' => ReminderStatus::Scheduled,
            'run_at' => CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'),
            'metadata' => ['attempts' => 0, 'delivery_state' => ReminderDeliveryState::STATE_NO_CHANNEL],
        ]);

        $row = (new ReminderService)->serializeForPanel($reminder, 'Europe/Rome', false);

        $this->assertTrue($row['is_due']);
        $this->assertSame('scheduled', $row['status']);
        $this->assertSame('no_channel', $row['delivery_state']);
        $this->assertNull($row['delivery_channel']);
        $this->assertFalse($row['delivery_available']);
        $this->assertTrue($row['cancellable']);
        $this->assertNull($row['last_error']);
        $this->assertArrayNotHasKey('metadata', $row);
        $this->assertArrayNotHasKey('attempts', $row);
    }

    public function test_panel_row_marks_future_scheduled_reminder_as_not_due(): void
    {
        $this->travelTo('2026-09-06 12:00:00');

        $reminder = $this->reminder([
            'status' => ReminderStatus::Scheduled,
            'run_at' => CarbonImmutable::parse('2026-09-06 13:00:00', 'UTC'),
            'metadata' => ['attempts' => 0],
        ]);

        $row = (new ReminderService)->serializeForPanel($reminder, 'Europe/Rome', false);

        $this->assertFalse($row['is_due']);
        $this->assertNull($row['delivery_state']);
    }

    public function test_owned_cancel_marks_scheduled_reminder_cancelled(): void
    {
        $user = $this->user(UserRole::User, UserStatus::Active, 4);
        $reminder = $this->reminder([
            'id' => 22,
            'user_id' => 4,
            'status' => ReminderStatus::Scheduled,
        ]);

        $service = new ReminderService;
        $owned = $service->assertOwnedCancellable($user, $reminder);
        $service->markCancelled($owned);

        $this->assertSame($reminder, $owned);
        $this->assertSame(ReminderStatus::Cancelled, $reminder->status);
        $this->assertNotNull($reminder->cancelled_at);
    }

    public function test_foreign_reminder_cannot_be_cancelled(): void
    {
        $user = $this->user(UserRole::User, UserStatus::Active, 4);
        $foreign = $this->reminder([
            'id' => 23,
            'user_id' => 99,
            'status' => ReminderStatus::Scheduled,
        ]);

        try {
            (new ReminderService)->assertOwnedCancellable($user, $foreign);
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('not_found', $exception->error);
            $this->assertSame(ReminderStatus::Scheduled, $foreign->status);
        }
    }

    public function test_missing_reminder_cannot_be_cancelled(): void
    {
        try {
            (new ReminderService)->assertOwnedCancellable($this->user(UserRole::User, UserStatus::Active, 4), null);
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('not_found', $exception->error);
        }
    }

    private function user(UserRole $role, UserStatus $status = UserStatus::Active, int $id = 1): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => 'Test User',
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
    private function reminder(array $attributes): Reminder
    {
        $reminder = new Reminder;
        $reminder->forceFill(array_merge([
            'id' => 10,
            'user_id' => 1,
            'text' => 'task',
            'run_at' => CarbonImmutable::parse('2026-09-06 15:00:00', 'UTC'),
            'timezone' => 'Europe/Rome',
            'status' => ReminderStatus::Scheduled,
            'metadata' => ['attempts' => 0],
        ], $attributes));

        return $reminder;
    }
}
