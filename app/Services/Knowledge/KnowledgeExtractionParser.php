<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationType;
use App\Services\Knowledge\Exceptions\KnowledgeExtractionException;
use App\Services\Memory\StructuredJsonParser;

final class KnowledgeExtractionParser
{
    /**
     * @return array{entities: list<array<string, mixed>>, relationships: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function parse(string $text): array
    {
        try {
            $payload = StructuredJsonParser::objectFromText($text);
        } catch (\Throwable $exception) {
            throw new KnowledgeExtractionException('Analysis AI did not return a JSON object.', 0, $exception);
        }

        return [
            'entities' => $this->entities($payload['entities'] ?? []),
            'relationships' => $this->relationships($payload['relationships'] ?? []),
            'events' => $this->events($payload['events'] ?? []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entities(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new KnowledgeExtractionException('entities must be an array.');
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = KnowledgeEntityType::tryFromLoose($row['type'] ?? null);
            $name = trim((string) ($row['name'] ?? ''));

            if ($type === null || $name === '') {
                continue;
            }

            $out[] = [
                'key' => isset($row['key']) ? (string) $row['key'] : $type->value.':'.KnowledgeNameNormalizer::name($name),
                'type' => $type->value,
                'name' => $name,
                'summary' => isset($row['summary']) ? (string) $row['summary'] : null,
                'aliases' => is_array($row['aliases'] ?? null) ? array_values(array_filter($row['aliases'], 'is_string')) : [],
                'kind' => mb_strtolower((string) ($row['kind'] ?? 'explicit')),
                'confidence' => isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
                'confidence_label' => isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationships(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new KnowledgeExtractionException('relationships must be an array.');
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = KnowledgeRelationType::tryFromLoose($row['type'] ?? null);

            if ($type === null) {
                continue;
            }

            $out[] = [
                'type' => $type->value,
                'source' => $row['source'] ?? $row['from'] ?? null,
                'target' => $row['target'] ?? $row['to'] ?? null,
                'label' => isset($row['label']) ? (string) $row['label'] : null,
                'deactivate' => (bool) ($row['deactivate'] ?? false),
                'kind' => mb_strtolower((string) ($row['kind'] ?? 'explicit')),
                'confidence' => isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
                'confidence_label' => isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new KnowledgeExtractionException('events must be an array.');
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = KnowledgeEventType::tryFromLoose($row['type'] ?? null);
            $title = trim((string) ($row['title'] ?? ''));

            if ($type === null || $title === '') {
                continue;
            }

            $out[] = [
                'type' => $type->value,
                'title' => $title,
                'entities' => is_array($row['entities'] ?? null) ? $row['entities'] : [],
                'kind' => mb_strtolower((string) ($row['kind'] ?? 'explicit')),
                'confidence' => isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null,
                'confidence_label' => isset($row['confidence_label']) ? (string) $row['confidence_label'] : null,
                'actor' => isset($row['actor']) ? (string) $row['actor'] : null,
                'side' => isset($row['side']) ? (string) $row['side'] : null,
                'action' => isset($row['action']) ? (string) $row['action'] : null,
                'due_at' => isset($row['due_at']) ? (string) $row['due_at'] : null,
                'explicit' => (bool) ($row['explicit'] ?? false),
            ];
        }

        return $out;
    }
}
