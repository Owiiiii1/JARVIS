<?php

namespace App\Services\Productivity;

use App\Enums\ProductivityBriefMode;

final class ProductivityBriefRenderer
{
    public function deterministic(ProductivityBriefSources $sources): string
    {
        $mode = ProductivityBriefMode::tryFrom($sources->mode) ?? ProductivityBriefMode::Daily;
        $heading = match ($mode) {
            ProductivityBriefMode::Daily => 'Ежедневная сводка',
            ProductivityBriefMode::Evening => 'Вечерний обзор',
            ProductivityBriefMode::Weekly => 'Недельный обзор',
        };

        $lines = [
            $heading.' · '.$sources->localDate.' ('.$sources->timezone.')',
        ];

        if ($mode === ProductivityBriefMode::Daily) {
            $lines[] = $this->section('Сегодня в календаре', $sources->calendar);
            $lines[] = $this->section('Задачи на сегодня', $sources->tasksToday);
            $lines[] = $this->section('Просроченные задачи', $sources->tasksOverdue);
            $lines[] = $this->section('Ближайшие напоминания', $sources->reminders);
            $lines[] = $this->section('Дальше', $sources->tasksUpcoming);
        } elseif ($mode === ProductivityBriefMode::Evening) {
            $lines[] = $this->section('Сделано сегодня', $sources->tasksCompleted);
            $lines[] = $this->section('Ещё открыто / просрочено', array_merge($sources->tasksOverdue, $sources->tasksToday));
            $lines[] = $this->section('Важно завтра', $sources->tasksUpcoming);
        } else {
            $lines[] = $this->section('Сделано за неделю', $sources->tasksCompleted);
            $lines[] = $this->section('Нерешённые просроченные', $sources->tasksOverdue);
            $lines[] = $this->section('Дедлайны на следующую неделю', $sources->tasksUpcoming);
        }

        if ($sources->projects !== []) {
            $lines[] = $this->section('Проекты', $sources->projects);
        }

        if ($sources->attention !== []) {
            $lines[] = 'Требует внимания: '.implode('; ', $sources->attention);
        }

        $text = trim(implode("\n", array_filter($lines)));

        return $text !== '' ? $text : $heading.': нет активных задач и напоминаний.';
    }

    /**
     * @param  list<array{title: string}>  $items
     */
    private function section(string $title, array $items): string
    {
        if ($items === []) {
            return $title.': нет.';
        }

        $names = array_map(static fn (array $item): string => (string) ($item['title'] ?? ''), $items);
        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));

        return $title.': '.implode('; ', array_slice($names, 0, 8));
    }
}
