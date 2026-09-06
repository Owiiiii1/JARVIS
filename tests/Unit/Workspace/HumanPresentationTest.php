<?php

namespace Tests\Unit\Workspace;

use App\Enums\KnowledgeRelationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanRelationLabel;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use App\Services\Workspace\Presentation\HumanSynthesisText;
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
}
