<?php

namespace Tests\Unit\Workspace;

use App\Enums\KnowledgeRelationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherMode;
use App\Enums\WatcherReactionType;
use App\Enums\WatcherTriggerType;
use App\Models\Watcher;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanRelationLabel;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use App\Services\Workspace\Presentation\HumanSynthesisText;
use App\Services\Workspace\Presentation\HumanWatcherDescription;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HumanPresentationTest extends TestCase
{
    public function test_moment_reads_as_a_relative_day_and_time(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $utc = static fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'UTC');

        $this->assertSame('Сегодня, 20:00', HumanMoment::label($utc('2026-09-06 18:00:00'), 'Europe/Rome', $now));
        $this->assertSame('Завтра, 11:00', HumanMoment::label($utc('2026-09-07 09:00:00'), 'Europe/Rome', $now));
        $this->assertSame('Вчера, 09:30', HumanMoment::label($utc('2026-09-05 07:30:00'), 'Europe/Rome', $now));
        $this->assertSame('10 сент., 18:30', HumanMoment::label($utc('2026-09-10 16:30:00'), 'Europe/Rome', $now));
        $this->assertSame('1 февр. 2027, 10:00', HumanMoment::label($utc('2027-02-01 09:00:00'), 'Europe/Rome', $now));
        $this->assertNull(HumanMoment::label(null, 'Europe/Rome', $now));
    }

    public function test_durations_are_pluralised(): void
    {
        $this->assertSame('1 час', HumanMoment::hours(1));
        $this->assertSame('2 часа', HumanMoment::hours(2));
        $this->assertSame('5 часов', HumanMoment::hours(5));
        $this->assertSame('11 часов', HumanMoment::hours(11));
        $this->assertSame('21 час', HumanMoment::hours(21));
        $this->assertSame('сутки', HumanMoment::hours(24));
        $this->assertSame('2 дня', HumanMoment::hours(48));
        $this->assertSame('5 дней', HumanMoment::hours(120));
    }

    public function test_task_labels_stay_silent_when_there_is_nothing_to_say(): void
    {
        $this->assertNull(HumanStatusLabel::activeTaskStatus(TaskStatus::Open));
        $this->assertSame('В работе', HumanStatusLabel::activeTaskStatus(TaskStatus::InProgress));
        $this->assertNull(HumanStatusLabel::taskPriority(TaskPriority::Normal));
        $this->assertNull(HumanStatusLabel::taskPriority(TaskPriority::Low));
        $this->assertSame('Высокий приоритет', HumanStatusLabel::taskPriority(TaskPriority::High));
        $this->assertSame('Срочно', HumanStatusLabel::taskPriority(TaskPriority::Urgent));
        $this->assertNull(HumanStatusLabel::subtaskProgress(0, 0));
        $this->assertSame('1 из 2 подзадач выполнено', HumanStatusLabel::subtaskProgress(1, 2));
        $this->assertSame('0 из 1 подзадачи выполнено', HumanStatusLabel::subtaskProgress(0, 1));
    }

    public function test_ids_written_into_knowledge_names_are_dropped(): void
    {
        $this->assertSame('Проверить новый билд YFS', HumanSynthesisText::entityName('Задача #253: Проверить новый билд YFS'));
        $this->assertSame('Проверить авторизацию', HumanSynthesisText::entityName('Подзадача #254 - Проверить авторизацию'));
        $this->assertSame('«Проверить авторизацию»', HumanSynthesisText::quote('Задача #254: Проверить авторизацию'));
    }

    public function test_relations_read_as_sentences_instead_of_enum_names(): void
    {
        $this->assertSame('Marco работает над YFS', HumanSynthesisText::relationEvent('Marco works_on YFS'));
        $this->assertSame('Marco связан с YFS', HumanSynthesisText::relationEvent('Marco related_to YFS'));
        $this->assertSame(
            '«Проверить авторизацию» зависит от «Проверить новый билд YFS»',
            HumanSynthesisText::relationEvent('Проверить авторизацию depends_on Проверить новый билд YFS'),
        );
        $this->assertNull(HumanSynthesisText::relationEvent('Marco прислал дизайн'));
        $this->assertSame('Marco работает над YFS', HumanRelationLabel::sentence(KnowledgeRelationType::WorksOn, 'Marco', 'YFS'));
    }

    public function test_waiting_and_completion_wording_names_the_work(): void
    {
        $this->assertSame('Ждём выполнения «Проверить авторизацию»', HumanSynthesisText::waitingTitle('Проверить авторизацию'));
        $this->assertSame('Срок наступает в ближайшие сутки', HumanSynthesisText::deadline(24));
        $this->assertSame('Сработала автоматизация «Проверка билда»', HumanSynthesisText::watcherTriggered('Проверка билда'));
        $this->assertStringNotContainsString('#', HumanSynthesisText::waitingTitle('Задача #254: Проверить авторизацию'));
    }

    public function test_still_open_tomorrow_watcher_names_the_delay(): void
    {
        $watcher = new Watcher([
            'trigger_type' => WatcherTriggerType::TaskState,
            'condition_type' => WatcherConditionType::StatusEquals,
            'condition_config' => ['status' => 'open', 'hours' => 24],
            'reaction_type' => WatcherReactionType::Notify,
            'mode' => WatcherMode::OneShot,
        ]);

        $this->assertSame(
            'Если «VC2 проверить новый билд» завтра всё ещё будет открытой, я сообщу вам.',
            HumanWatcherDescription::sentence($watcher, ['task' => 'VC2 проверить новый билд']),
        );
    }

    public function test_gmail_morning_digest_names_the_schedule_without_watcher_jargon(): void
    {
        $watcher = new Watcher([
            'trigger_type' => WatcherTriggerType::GmailMessage,
            'condition_type' => WatcherConditionType::NewItem,
            'source_config' => [
                'digest' => true,
                'query' => 'in:inbox',
                'schedule' => ['kind' => 'daily_local', 'local_time' => '08:00'],
            ],
            'reaction_type' => WatcherReactionType::Notify,
            'mode' => WatcherMode::Recurring,
        ]);

        $this->assertSame(
            'Каждое утро около 8:00 буду проверять Gmail и присылать короткую сводку новых писем.',
            HumanWatcherDescription::sentence($watcher),
        );
        $this->assertStringNotContainsString('watcher', mb_strtolower(HumanWatcherDescription::sentence($watcher)));
        $this->assertStringNotContainsString('gmail_message', HumanWatcherDescription::sentence($watcher));
    }
}
