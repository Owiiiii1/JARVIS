<?php

namespace App\Services\Memory;

use App\Enums\MemoryAction;
use App\Enums\MemoryKind;
use App\Services\Memory\DTO\MemoryAnalysisResult;
use App\Services\Memory\DTO\MemoryCandidate;
use App\Services\Memory\DTO\TopicCandidate;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use Carbon\CarbonImmutable;
use Throwable;

final class MemoryAnalysisResultParser
{
    /**
     * @param  list<int>  $allowedMessageIds
     */
    public function parse(string $text, array $allowedMessageIds): MemoryAnalysisResult
    {
        $payload = StructuredJsonParser::objectFromText($text);

        return new MemoryAnalysisResult(
            topics: $this->topics($payload['topics'] ?? []),
            memories: $this->memories($payload['memories'] ?? [], $allowedMessageIds),
            profileCandidate: $this->optionalString($payload['profile_candidate'] ?? $payload['profileCandidate'] ?? null),
        );
    }

    /**
     * @param  mixed  $rows
     * @return list<TopicCandidate>
     */
    private function topics(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new MemoryAnalysisException('topics must be an array.');
        }

        $topics = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new MemoryAnalysisException('topic candidate must be an object.');
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $topics[] = new TopicCandidate(
                name: mb_substr($name, 0, 120),
                description: $this->optionalString($row['description'] ?? null, 500),
                confidence: $this->confidence($row['confidence'] ?? 0.8),
                messageIds: $this->ids($row['message_ids'] ?? $row['messageIds'] ?? []),
            );
        }

        return $topics;
    }

    /**
     * @param  mixed  $rows
     * @param  list<int>  $allowedMessageIds
     * @return list<MemoryCandidate>
     */
    private function memories(mixed $rows, array $allowedMessageIds): array
    {
        if (! is_array($rows)) {
            throw new MemoryAnalysisException('memories must be an array.');
        }

        $memories = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new MemoryAnalysisException('memory candidate must be an object.');
            }

            $action = strtolower(trim((string) ($row['action'] ?? 'create')));

            if (MemoryAction::tryFrom($action) === null) {
                throw new MemoryAnalysisException('Unknown memory action: '.$action);
            }

            if ($action === MemoryAction::Ignore->value) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? ''));

            if ($content === '') {
                throw new MemoryAnalysisException('Memory content cannot be empty.');
            }

            $kind = strtolower(trim((string) ($row['kind'] ?? 'other')));

            if (MemoryKind::tryFrom($kind) === null) {
                throw new MemoryAnalysisException('Unknown memory kind: '.$kind);
            }

            $sourceIds = $this->ids($row['source_message_ids'] ?? $row['sourceMessageIds'] ?? []);
            $sourceIds = array_values(array_intersect($sourceIds, $allowedMessageIds));

            $memories[] = new MemoryCandidate(
                kind: $kind,
                content: mb_substr($content, 0, 2000),
                normalizedKey: $this->optionalString($row['normalized_key'] ?? $row['normalizedKey'] ?? null, 180),
                confidence: $this->confidence($row['confidence'] ?? 0),
                action: $action,
                validFrom: $this->optionalDate($row['valid_from'] ?? $row['validFrom'] ?? null),
                validUntil: $this->optionalDate($row['valid_until'] ?? $row['validUntil'] ?? null),
                supersedeNormalizedKey: $this->optionalString($row['supersede_normalized_key'] ?? $row['supersedeNormalizedKey'] ?? null, 180),
                sourceMessageIds: $sourceIds,
                reason: $this->optionalString($row['reason'] ?? null, 255),
            );
        }

        return $memories;
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new MemoryAnalysisException('Confidence must be numeric.');
        }

        $confidence = (float) $value;

        if ($confidence < 0 || $confidence > 1) {
            throw new MemoryAnalysisException('Confidence must be between 0 and 1.');
        }

        return round($confidence, 4);
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (! is_numeric($item) || (int) $item <= 0) {
                throw new MemoryAnalysisException('Malformed message id in analysis output.');
            }

            $ids[] = (int) $item;
        }

        return array_values(array_unique($ids));
    }

    private function optionalString(mixed $value, int $max = 2000): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function optionalDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            throw new MemoryAnalysisException('Invalid temporal memory date.');
        }
    }
}
