<?php

namespace App\Services\Synthesis;

use App\Models\KnowledgeEvent;
use App\Models\WatcherOccurrence;
use App\Services\Knowledge\KnowledgeNameNormalizer;
use App\Services\Synthesis\DTO\SynthesisItem;
use Carbon\CarbonImmutable;

final class SynthesisDeduplicator
{
    /**
     * @param  list<SynthesisItem>  $items
     * @return list<SynthesisItem>
     */
    public function items(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $item) {
            $key = $item->fingerprint !== '' ? $item->fingerprint : $this->fingerprint($item);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    public function eventKey(KnowledgeEvent $event): string
    {
        if (is_string($event->source_fingerprint) && $event->source_fingerprint !== '') {
            return 'fp:'.$event->source_fingerprint;
        }

        return 'knowledge_event:'.$event->id;
    }

    public function occurrenceKey(WatcherOccurrence $occurrence): string
    {
        $metadata = $occurrence->metadata ?? [];
        $fingerprint = (string) ($occurrence->trigger_fingerprint ?? $metadata['source_fingerprint'] ?? '');

        if ($fingerprint !== '') {
            return 'fp:'.$fingerprint;
        }

        if ($occurrence->knowledge_event_id) {
            return 'knowledge_event:'.$occurrence->knowledge_event_id;
        }

        $day = $occurrence->detected_at instanceof CarbonImmutable
            ? $occurrence->detected_at->toDateString()
            : '';

        return 'occurrence:'.$occurrence->id.':'.$day;
    }

    public function fallbackKey(?int $entityId, string $kind, string $title, ?CarbonImmutable $at): string
    {
        $day = $at?->toDateString() ?? '';

        return implode(':', [
            'fallback',
            (string) ($entityId ?? 0),
            $kind,
            KnowledgeNameNormalizer::name($title),
            $day,
        ]);
    }

    private function fingerprint(SynthesisItem $item): string
    {
        foreach ($item->sources as $source) {
            if ($source->knowledgeEventId) {
                return 'knowledge_event:'.$source->knowledgeEventId;
            }

            if (is_string($source->sourceFingerprint) && $source->sourceFingerprint !== '') {
                return 'fp:'.$source->sourceFingerprint;
            }

            if ($source->occurrenceId && $source->knowledgeEventId === null) {
                return 'occurrence:'.$source->occurrenceId;
            }
        }

        return $this->fallbackKey(
            $item->sources[0]->entityId ?? null,
            $item->kind,
            $item->title,
            is_string($item->since) ? CarbonImmutable::parse($item->since) : null,
        );
    }
}
