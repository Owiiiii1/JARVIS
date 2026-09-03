<?php

namespace App\Services\Groups;

use App\Services\Groups\DTO\GroupAnalysisResult;
use App\Services\Groups\DTO\GroupDecisionCandidate;
use App\Services\Groups\DTO\GroupEventCandidate;
use App\Services\Groups\DTO\GroupSummaryCandidate;
use App\Services\Groups\DTO\GroupTaskCandidate;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use App\Services\Memory\StructuredJsonParser;

final class GroupAnalysisResultParser
{
    /**
     * @param  list<int>  $allowedMessageIds
     */
    public function parse(string $text, array $allowedMessageIds): GroupAnalysisResult
    {
        try {
            $payload = StructuredJsonParser::objectFromText($text);
        } catch (MemoryAnalysisException $exception) {
            throw new GroupAnalysisException($exception->getMessage(), 0, $exception);
        }

        return new GroupAnalysisResult(
            summary: $this->summary($payload['summary'] ?? null, $allowedMessageIds),
            decisions: $this->decisions($payload['decisions'] ?? [], $allowedMessageIds),
            tasks: $this->tasks($payload['tasks'] ?? [], $allowedMessageIds),
            events: $this->events($payload['events'] ?? $payload['event_facts'] ?? [], $allowedMessageIds),
        );
    }

    /**
     * @param  list<int>  $allowedMessageIds
     */
    private function summary(mixed $row, array $allowedMessageIds): ?GroupSummaryCandidate
    {
        if ($row === null) {
            return null;
        }

        if (! is_array($row)) {
            throw new GroupAnalysisException('summary must be an object or null.');
        }

        $content = trim((string) ($row['content'] ?? ''));

        if ($content === '') {
            return null;
        }

        return new GroupSummaryCandidate(
            content: mb_substr($content, 0, 4000),
            confidence: $this->confidence($row['confidence'] ?? 0.8),
            sourceMessageIds: $this->requiredSourceIds($row, $allowedMessageIds, allowEmpty: true),
        );
    }

    /**
     * @param  mixed  $rows
     * @param  list<int>  $allowedMessageIds
     * @return list<GroupDecisionCandidate>
     */
    private function decisions(mixed $rows, array $allowedMessageIds): array
    {
        $max = (int) config('group_analysis.max_decisions');
        $items = [];

        foreach ($this->objects($rows, 'decisions') as $row) {
            $content = $this->requiredContent($row['content'] ?? null, 'decision');

            if ($content === null) {
                continue;
            }

            $items[] = new GroupDecisionCandidate(
                content: $content,
                confidence: $this->confidence($row['confidence'] ?? 0),
                sourceMessageIds: $this->requiredSourceIds($row, $allowedMessageIds),
                participants: $this->stringList($row['participants'] ?? []),
                effectiveDateLocal: $this->optionalLocalDate($row['effective_date_local'] ?? $row['effectiveDateLocal'] ?? null),
                supersedesNormalizedKey: $this->optionalString($row['supersedes_normalized_key'] ?? $row['supersedesNormalizedKey'] ?? null, 180),
                threadId: $this->optionalInt($row['thread_id'] ?? $row['threadId'] ?? null),
            );

            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  mixed  $rows
     * @param  list<int>  $allowedMessageIds
     * @return list<GroupTaskCandidate>
     */
    private function tasks(mixed $rows, array $allowedMessageIds): array
    {
        $max = (int) config('group_analysis.max_tasks');
        $items = [];

        foreach ($this->objects($rows, 'tasks') as $row) {
            $content = $this->requiredContent($row['content'] ?? $row['task'] ?? null, 'task');

            if ($content === null) {
                continue;
            }

            $items[] = new GroupTaskCandidate(
                content: $content,
                confidence: $this->confidence($row['confidence'] ?? 0),
                sourceMessageIds: $this->requiredSourceIds($row, $allowedMessageIds),
                assigneeText: $this->optionalString($row['assignee_text'] ?? $row['assigneeText'] ?? null, 120),
                dueAtLocal: $this->optionalLocalDate($row['due_at_local'] ?? $row['dueAtLocal'] ?? null),
                statusHint: $this->optionalString($row['status_hint'] ?? $row['statusHint'] ?? null, 32),
                supersedesNormalizedKey: $this->optionalString($row['supersedes_normalized_key'] ?? $row['supersedesNormalizedKey'] ?? null, 180),
                threadId: $this->optionalInt($row['thread_id'] ?? $row['threadId'] ?? null),
            );

            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  mixed  $rows
     * @param  list<int>  $allowedMessageIds
     * @return list<GroupEventCandidate>
     */
    private function events(mixed $rows, array $allowedMessageIds): array
    {
        $max = (int) config('group_analysis.max_events');
        $items = [];

        foreach ($this->objects($rows, 'events') as $row) {
            $content = $this->requiredContent($row['content'] ?? null, 'event');

            if ($content === null) {
                continue;
            }

            $items[] = new GroupEventCandidate(
                content: $content,
                confidence: $this->confidence($row['confidence'] ?? 0),
                sourceMessageIds: $this->requiredSourceIds($row, $allowedMessageIds),
                occurredAtLocal: $this->optionalLocalDate($row['occurred_at_local'] ?? $row['occurredAtLocal'] ?? null),
                supersedesNormalizedKey: $this->optionalString($row['supersedes_normalized_key'] ?? $row['supersedesNormalizedKey'] ?? null, 180),
                threadId: $this->optionalInt($row['thread_id'] ?? $row['threadId'] ?? null),
            );

            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function objects(mixed $rows, string $field): array
    {
        if (! is_array($rows)) {
            throw new GroupAnalysisException($field.' must be an array.');
        }

        $objects = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GroupAnalysisException($field.' items must be objects.');
            }

            $objects[] = $row;
        }

        return $objects;
    }

    private function requiredContent(mixed $value, string $label): ?string
    {
        if (! is_string($value)) {
            throw new GroupAnalysisException($label.' content must be a string.');
        }

        $content = trim($value);

        if ($content === '') {
            return null;
        }

        return mb_substr($content, 0, 4000);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $allowedMessageIds
     * @return list<int>
     */
    private function requiredSourceIds(array $row, array $allowedMessageIds, bool $allowEmpty = false): array
    {
        $raw = $row['source_message_ids'] ?? $row['sourceMessageIds'] ?? [];

        if (! is_array($raw)) {
            throw new GroupAnalysisException('source_message_ids must be an array.');
        }

        $ids = [];

        foreach ($raw as $item) {
            if (! is_numeric($item) || (int) $item <= 0) {
                throw new GroupAnalysisException('Malformed source message id.');
            }

            $id = (int) $item;

            if (! in_array($id, $allowedMessageIds, true)) {
                throw new GroupAnalysisException('Source message id is not in the analysed group range.');
            }

            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === [] && ! $allowEmpty) {
            throw new GroupAnalysisException('Derived group knowledge requires source messages.');
        }

        return $ids;
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new GroupAnalysisException('Confidence must be numeric.');
        }

        $confidence = (float) $value;

        if ($confidence < 0 || $confidence > 1) {
            throw new GroupAnalysisException('Confidence must be between 0 and 1.');
        }

        return round($confidence, 4);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item !== '') {
                $items[] = mb_substr($item, 0, 120);
            }
        }

        return array_values(array_unique($items));
    }

    private function optionalString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function optionalInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function optionalLocalDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
