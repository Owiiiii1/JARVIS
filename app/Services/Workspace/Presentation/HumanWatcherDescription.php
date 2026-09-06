<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\WatcherConditionType;
use App\Enums\WatcherMode;
use App\Enums\WatcherReactionType;
use App\Enums\WatcherTriggerType;
use App\Models\Watcher;

/**
 * Builds the single human sentence a watcher card shows instead of its internal DSL:
 * «Если «Проверить авторизацию» останется открытой, я сообщу вам».
 */
final class HumanWatcherDescription
{
    /**
     * @param  array{task?: ?string, entity?: ?string, project?: ?string, reminder?: ?string}  $names
     */
    public static function sentence(Watcher $watcher, array $names = [], string $timezone = 'UTC'): string
    {
        $condition = self::condition($watcher, $names, $timezone);
        $reaction = self::reaction($watcher);

        if ($condition === null) {
            return ucfirst($reaction).', когда сработает условие этой автоматизации.';
        }

        return self::upper($condition).', '.$reaction.'.';
    }

    /**
     * @param  array{task?: ?string, entity?: ?string, project?: ?string, reminder?: ?string}  $names
     */
    private static function condition(Watcher $watcher, array $names, string $timezone): ?string
    {
        $subject = self::subject($watcher, $names);
        $config = is_array($watcher->condition_config) ? $watcher->condition_config : [];

        return match ($watcher->condition_type) {
            WatcherConditionType::StatusEquals => self::statusEquals($subject, $config),
            WatcherConditionType::StatusChanged => 'если статус '.$subject.' изменится',
            WatcherConditionType::OverdueBy => self::overdueBy($subject, $config),
            WatcherConditionType::DeadlineWithin => self::deadlineWithin($subject, $config, $watcher, $timezone),
            WatcherConditionType::ThreadReceivedReply => 'если в переписке '.$subject.' появится ответ',
            WatcherConditionType::SenderMatches => self::senderMatches($config),
            WatcherConditionType::SubjectContains => self::subjectContains($config),
            WatcherConditionType::NewItem, WatcherConditionType::EventExists => 'если появится что-то новое по '.$subject,
            WatcherConditionType::EntityEventType => 'если появится новое событие по '.$subject,
            WatcherConditionType::CalendarChanged => 'если календарь изменится',
            WatcherConditionType::GithubNewCommit => 'если в репозитории появится новый коммит',
            WatcherConditionType::GithubPrStateChanged => 'если состояние pull request изменится',
            WatcherConditionType::GithubWorkflowFailed => 'если сборка завершится с ошибкой',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function statusEquals(string $subject, array $config): string
    {
        $status = mb_strtolower((string) ($config['status'] ?? $config['expected'] ?? ''));

        return match ($status) {
            'open' => 'если '.$subject.' останется открытой',
            'in_progress' => 'если '.$subject.' останется в работе',
            'completed' => 'когда '.$subject.' будет выполнена',
            'cancelled' => 'если '.$subject.' отменят',
            default => 'если состояние '.$subject.' не изменится',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function overdueBy(string $subject, array $config): string
    {
        $hours = (int) ($config['hours'] ?? $config['overdue_by_hours'] ?? 0);

        if ($hours > 0) {
            return 'если '.$subject.' просрочится больше чем на '.HumanMoment::hours($hours);
        }

        return 'если срок по '.$subject.' пройдёт, а работа останется открытой';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function deadlineWithin(string $subject, array $config, Watcher $watcher, string $timezone): string
    {
        $hours = (int) ($config['hours'] ?? $config['within_hours'] ?? 0);
        $checkAt = HumanMoment::dayLabel($watcher->next_check_at, $timezone);

        if ($hours > 0) {
            return 'если до срока по '.$subject.' останется меньше '.HumanMoment::hours($hours);
        }

        return $checkAt !== null
            ? 'если '.$subject.' не будет закрыта к '.mb_strtolower($checkAt)
            : 'когда подойдёт срок по '.$subject;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function senderMatches(array $config): string
    {
        $sender = trim((string) ($config['sender'] ?? $config['from'] ?? ''));

        return $sender !== ''
            ? 'если придёт письмо от '.$sender
            : 'если придёт письмо от отслеживаемого отправителя';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function subjectContains(array $config): string
    {
        $needle = trim((string) ($config['contains'] ?? $config['subject'] ?? ''));

        return $needle !== ''
            ? 'если в теме письма встретится «'.$needle.'»'
            : 'если тема письма совпадёт с условием';
    }

    /**
     * @param  array{task?: ?string, entity?: ?string, project?: ?string, reminder?: ?string}  $names
     */
    private static function subject(Watcher $watcher, array $names): string
    {
        $quoted = static fn (?string $name): ?string => is_string($name) && trim($name) !== ''
            ? '«'.trim($name).'»'
            : null;

        $candidate = match ($watcher->trigger_type) {
            WatcherTriggerType::TaskState, WatcherTriggerType::TimeCondition => $quoted($names['task'] ?? null),
            WatcherTriggerType::ReminderState => $quoted($names['reminder'] ?? null),
            WatcherTriggerType::KnowledgeEvent => $quoted($names['entity'] ?? null),
            WatcherTriggerType::GmailMessage => 'почте',
            WatcherTriggerType::CalendarEvent => 'календаре',
            WatcherTriggerType::GithubEvent => 'репозитории',
        };

        return $candidate
            ?? $quoted($names['task'] ?? null)
            ?? $quoted($names['entity'] ?? null)
            ?? $quoted($names['project'] ?? null)
            ?? 'этой работе';
    }

    private static function reaction(Watcher $watcher): string
    {
        $once = $watcher->mode === WatcherMode::OneShot;

        return match ($watcher->reaction_type) {
            WatcherReactionType::Notify, WatcherReactionType::CreateNotification => $once
                ? 'я сообщу вам'
                : 'я буду сообщать вам',
            WatcherReactionType::CreateReminder => 'я создам напоминание',
            WatcherReactionType::CreateTask => 'я создам задачу',
            WatcherReactionType::RunInternalAnalysis => 'я разберу это и подготовлю выводы',
            WatcherReactionType::ProposeAction => 'я предложу, что сделать',
        };
    }

    private static function upper(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
