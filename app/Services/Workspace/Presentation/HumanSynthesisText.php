<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationType;
use App\Enums\TaskStatus;
use App\Models\KnowledgeEvent;
use App\Models\Task;

/**
 * Wording for derived synthesis items.
 *
 * Every slice that reaches a person — the Overview panel and the synthesis tool results the
 * assistant reads — goes through here, so neither the user nor the model ever sees an enum
 * value such as `task_completed` or `overdue_by` as if it were a sentence.
 */
final class HumanSynthesisText
{
    public static function taskChange(Task $task): string
    {
        $title = self::quote($task->title);

        return match ($task->status) {
            TaskStatus::Completed => 'Задача '.$title.' выполнена',
            TaskStatus::Cancelled => 'Задача '.$title.' отменена',
            TaskStatus::InProgress => 'Взялись за задачу '.$title,
            TaskStatus::Open => 'Задача '.$title.' обновлена',
        };
    }

    /**
     * A knowledge event describes something that happened.
     *
     * `$subject` is the canonical name when the event points at something we can name
     * authoritatively. Without it the stored title is already a written sentence — extraction
     * writes whole phrases — and wrapping it in another template only produces nonsense like
     * «Появилась задача «Создана подзадача #254»».
     */
    public static function knowledgeEvent(KnowledgeEvent $event, ?string $subject = null): string
    {
        if ($event->type === KnowledgeEventType::KnowledgeLinked
            || $event->type === KnowledgeEventType::RelationshipSuperseded) {
            return self::relationEvent((string) $event->title) ?? self::plain($event->title);
        }

        $subject = $subject !== null && trim($subject) !== '' ? $subject : null;

        if ($subject === null && ! self::looksLikeSentence((string) $event->title)) {
            $subject = (string) $event->title;
        }

        if ($subject === null) {
            return self::withoutIds($event->title);
        }

        $name = self::quote($subject);

        return match ($event->type) {
            KnowledgeEventType::TaskCreated => 'Появилась задача '.$name,
            KnowledgeEventType::TaskCompleted => 'Задача '.$name.' выполнена',
            KnowledgeEventType::ReminderCreated => 'Поставлено напоминание '.$name,
            KnowledgeEventType::ProjectCreated => 'Создан проект '.$name,
            KnowledgeEventType::ProjectArchived => 'Проект '.$name.' в архиве',
            KnowledgeEventType::EmailReceived => 'Новое письмо: '.self::plain($subject),
            KnowledgeEventType::CalendarEvent => 'Событие в календаре: '.self::plain($subject),
            KnowledgeEventType::GithubCommitSeen => 'Новый коммит: '.self::plain($subject),
            KnowledgeEventType::FileUploaded => 'Загружен файл '.$name,
            KnowledgeEventType::WatcherTriggered => 'Сработала автоматизация '.$name,
            default => self::plain($event->title),
        };
    }

    /**
     * Link events are stored as «source relation_type target»; rebuild that as a sentence.
     */
    public static function relationEvent(string $title): ?string
    {
        foreach (KnowledgeRelationType::cases() as $type) {
            $needle = ' '.$type->value.' ';
            $position = mb_strpos($title, $needle);

            if ($position === false) {
                continue;
            }

            $source = self::entityName(mb_substr($title, 0, $position));
            $target = self::entityName(mb_substr($title, $position + mb_strlen($needle)));

            if ($source === '' || $target === '') {
                return null;
            }

            // Multi-word names are titles of things and read better quoted; a bare name is a
            // person or a project and reads better as-is.
            $quoted = static fn (string $name): string => str_contains($name, ' ') ? '«'.$name.'»' : $name;

            return HumanRelationLabel::sentence($type, $quoted($source), $quoted($target));
        }

        return null;
    }

    /**
     * Knowledge names sometimes carry the id the assistant wrote into them; the user does not
     * need it, the canonical title is the same thing without the noise.
     */
    public static function entityName(?string $value): string
    {
        $value = trim((string) $value);
        $stripped = preg_replace('/^(задача|подзадача|task|subtask)\s*#\d+\s*[:\-–]\s*/iu', '', $value);

        return trim((string) ($stripped ?? $value));
    }

    public static function waitingTitle(string $subject): string
    {
        return 'Ждём выполнения '.self::quote($subject);
    }

    public static function waitingOn(string $subject): string
    {
        return 'Ждём ответа от '.self::plain($subject);
    }

    public static function watcherTriggered(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== ''
            ? 'Сработала автоматизация '.self::quote($name)
            : 'Сработала автоматизация';
    }

    public static function deadline(int $hours): string
    {
        return $hours <= 24
            ? 'Срок наступает в ближайшие сутки'
            : 'Срок наступает в ближайшие '.HumanMoment::hours($hours);
    }

    public static function quote(?string $value): string
    {
        $value = self::entityName($value);

        if ($value === '') {
            return '«без названия»';
        }

        return str_starts_with($value, '«') ? $value : '«'.$value.'»';
    }

    public static function plain(?string $value): string
    {
        return trim((string) $value);
    }

    /**
     * Extraction writes finished phrases; a name is a name. Only the former must be left alone.
     */
    private static function looksLikeSentence(string $title): bool
    {
        $title = trim($title);

        return $title === ''
            || str_contains($title, '«')
            || str_ends_with($title, '.')
            || mb_strlen($title) > 60;
    }

    private static function withoutIds(?string $value): string
    {
        $cleaned = preg_replace('/\s*#\d+/u', '', trim((string) $value));

        return trim((string) preg_replace('/\s{2,}/u', ' ', (string) $cleaned));
    }
}
