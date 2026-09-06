<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\ReminderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WatcherHealth;
use App\Enums\WatcherStatus;
use App\Models\Reminder;
use App\Models\Watcher;
use App\Services\Reminders\ReminderDeliveryState;

/**
 * Domain enums stay authoritative; these are the words the user reads instead of them.
 *
 * Anything that returns null is deliberately not shown in a normal state — a card only spends a
 * line on status when the status is not already implied by the section the card sits in.
 */
final class HumanStatusLabel
{
    public static function taskStatus(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Open => 'Открыта',
            TaskStatus::InProgress => 'В работе',
            TaskStatus::Completed => 'Выполнена',
            TaskStatus::Cancelled => 'Отменена',
        };
    }

    /**
     * Only an unusual status earns a line on an active card.
     */
    public static function activeTaskStatus(TaskStatus $status): ?string
    {
        return $status === TaskStatus::InProgress ? 'В работе' : null;
    }

    public static function taskPriority(TaskPriority $priority): ?string
    {
        return match ($priority) {
            TaskPriority::Urgent => 'Срочно',
            TaskPriority::High => 'Высокий приоритет',
            TaskPriority::Normal, TaskPriority::Low => null,
        };
    }

    /**
     * «2 из 3 подзадач выполнено» — progress, never a count of rows.
     */
    public static function subtaskProgress(int $done, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }

        $noun = ($total % 10 === 1 && $total % 100 !== 11) ? 'подзадачи' : 'подзадач';

        return $done.' из '.$total.' '.$noun.' выполнено';
    }

    public static function reminderStatus(ReminderStatus $status): string
    {
        return match ($status) {
            ReminderStatus::Scheduled, ReminderStatus::Processing => 'Запланировано',
            ReminderStatus::Delivered => 'Напомнили',
            ReminderStatus::Completed => 'Выполнено',
            ReminderStatus::Cancelled => 'Отменено',
            ReminderStatus::Failed => 'Не доставлено',
        };
    }

    /**
     * Delivery wording is a problem report, not a status line: silence means it worked.
     */
    public static function reminderProblem(Reminder $reminder, bool $deliveryAvailable): ?string
    {
        $state = is_array($reminder->metadata) ? ($reminder->metadata['delivery_state'] ?? null) : null;

        if ($reminder->status === ReminderStatus::Failed || $state === ReminderDeliveryState::STATE_ERROR) {
            return 'Не удалось доставить уведомление';
        }

        if ($state === ReminderDeliveryState::STATE_NO_CHANNEL || ! $deliveryAvailable) {
            return 'Нет активного канала уведомлений';
        }

        if ($state === ReminderDeliveryState::STATE_PARTIAL) {
            return 'Доставлено не во все каналы';
        }

        return null;
    }

    /**
     * One short line describing where a watcher stands, without health or status enums.
     */
    public static function watcherState(Watcher $watcher, string $timezone): ?string
    {
        if ($watcher->status === WatcherStatus::Paused) {
            return 'Приостановлено';
        }

        if ($watcher->status === WatcherStatus::Cancelled) {
            return 'Отменено';
        }

        if ($watcher->status === WatcherStatus::Completed) {
            $triggered = HumanMoment::label($watcher->last_triggered_at, $timezone);

            if (($watcher->cursor['resolved_reason'] ?? null) === 'task_closed') {
                return 'Больше не нужно — задача закрыта';
            }

            return $triggered !== null ? 'Сработало '.$triggered : 'Завершено';
        }

        if ($watcher->status === WatcherStatus::Failed || $watcher->health === WatcherHealth::Failed) {
            return 'Проверка не выполняется';
        }

        if ($watcher->health === WatcherHealth::Blocked) {
            return self::reconnectHint($watcher);
        }

        $triggered = HumanMoment::label($watcher->last_triggered_at, $timezone);

        if ($triggered !== null) {
            return 'Сработало '.$triggered;
        }

        return 'Ждёт события';
    }

    public static function watcherProblem(Watcher $watcher): ?string
    {
        if ($watcher->health === WatcherHealth::Blocked) {
            return self::reconnectHint($watcher);
        }

        if ($watcher->status === WatcherStatus::Failed || $watcher->health === WatcherHealth::Failed) {
            return 'Проверка не выполняется — откройте автоматизацию и запустите её заново';
        }

        return null;
    }

    private static function reconnectHint(Watcher $watcher): string
    {
        return match ($watcher->trigger_type->value) {
            'gmail_message' => 'Нужно переподключить Gmail',
            'calendar_event' => 'Нужно переподключить Календарь',
            'github_event' => 'Нужно переподключить GitHub',
            default => 'Нужно проверить подключение',
        };
    }
}
