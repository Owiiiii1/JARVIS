<?php

namespace App\Services\Reports;

use App\Enums\ScheduledReportType;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Productivity\SynthesizesProductivityBrief;

final class ScheduledReportComposer
{
    public function __construct(
        private readonly ?SynthesizesProductivityBrief $synthesizer = null,
    ) {}

    /**
     * @param  array{items: array<string, mixed>, errors: list<string>, local_date: string, timezone: string, period_mode: string, report_type: string}  $collected
     * @return array{text: string, ai_used: bool}
     */
    public function compose(User $user, ScheduledReport $report, array $collected): array
    {
        $deterministic = $this->deterministic($report, $collected);
        $text = $deterministic;
        $aiUsed = false;

        if ($this->synthesizer !== null) {
            $phrased = $this->synthesizer->synthesize($user, $report->report_type->value, $deterministic, [
                'report' => $report->name,
                'period_mode' => $collected['period_mode'] ?? null,
                'items' => $collected['items'] ?? [],
                'errors' => $collected['errors'] ?? [],
            ]);

            if (is_string($phrased) && trim($phrased) !== '') {
                $text = trim($phrased);
                $aiUsed = true;
            }
        }

        return [
            'text' => $text,
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * @param  array{items: array<string, mixed>, errors: list<string>, local_date?: string, timezone?: string}  $collected
     */
    public function deterministic(ScheduledReport $report, array $collected): string
    {
        $items = is_array($collected['items'] ?? null) ? $collected['items'] : [];
        $heading = match ($report->report_type) {
            ScheduledReportType::DailyPlan => 'Планы на сегодня',
            ScheduledReportType::TomorrowPlan => 'Планы на завтра',
            ScheduledReportType::MailGroupsDigest => 'Почта и группы',
            ScheduledReportType::CustomComposite => $report->name,
        };

        $lines = [
            $heading.' · '.($collected['local_date'] ?? '').' ('.($collected['timezone'] ?? '').')',
        ];

        if ($report->report_type === ScheduledReportType::MailGroupsDigest) {
            $lines[] = $this->mailSection($items['gmail'] ?? []);
            $lines[] = $this->groupsSection($items['telegram_groups'] ?? []);
        } else {
            $lines[] = $this->section('Календарь', $items['calendar'] ?? []);
            $lines[] = $this->section('Задачи', $items['tasks'] ?? []);
            $lines[] = $this->section('Напоминания', $items['reminders'] ?? []);
            $lines[] = $this->section('Планы и обязательства', $items['synthesis'] ?? []);
        }

        foreach ($collected['errors'] ?? [] as $error) {
            if (is_string($error) && trim($error) !== '') {
                $lines[] = trim($error).' Отчёт составлен по доступным источникам.';
            }
        }

        $text = trim(implode("\n", array_filter($lines)));

        return $text !== '' ? $text : $heading.': нет данных за этот период.';
    }

    /**
     * @param  list<array{title?: string}>  $items
     */
    private function section(string $title, array $items): string
    {
        if ($items === []) {
            return $title.': нет.';
        }

        $names = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['title'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $title.': '.implode('; ', array_slice($names, 0, 8));
    }

    /**
     * @param  list<array{sender?: string, subject?: string, bucket?: string}>  $items
     */
    private function mailSection(array $items): string
    {
        if ($items === []) {
            return 'Новых писем нет.';
        }

        $important = [];
        $normal = [];
        $noise = 0;

        foreach ($items as $item) {
            $bucket = (string) ($item['bucket'] ?? 'normal');
            if ($bucket === 'noise') {
                $noise++;

                continue;
            }

            $line = trim((string) ($item['sender'] ?? '')).' — '.trim((string) ($item['subject'] ?? 'без темы'));
            if ($bucket === 'important') {
                $important[] = $line;
            } else {
                $normal[] = $line;
            }
        }

        $lines = ['Письма: '.count($items).'.'];
        foreach (array_slice($important, 0, 5) as $line) {
            $lines[] = '• '.$line;
        }
        foreach (array_slice($normal, 0, 4) as $line) {
            $lines[] = '• '.$line;
        }
        if ($noise > 0) {
            $lines[] = '• Рекламных или технических: '.$noise.'.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{group?: string, count?: int, sample?: string}>  $items
     */
    private function groupsSection(array $items): string
    {
        if ($items === []) {
            return 'В группах новой активности нет.';
        }

        $lines = ['Группы:'];
        foreach (array_slice($items, 0, 6) as $item) {
            $name = (string) ($item['group'] ?? 'Группа');
            $count = (int) ($item['count'] ?? 0);
            $sample = trim((string) ($item['sample'] ?? ''));
            $lines[] = '• '.$name.': '.$count.($sample !== '' ? ' — '.$sample : '');
        }

        return implode("\n", $lines);
    }
}
