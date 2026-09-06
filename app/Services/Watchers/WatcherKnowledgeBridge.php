<?php

namespace App\Services\Watchers;

use App\Enums\KnowledgeEventType;
use App\Enums\WatcherTriggerType;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Watchers\DTO\WatcherObservation;
use Throwable;

final class WatcherKnowledgeBridge
{
    public function __construct(
        private readonly KnowledgeIngestionService $ingestion = new KnowledgeIngestionService,
    ) {}

    public function ingest(User $user, Watcher $watcher, WatcherObservation $observation): void
    {
        if (! $watcher->isExternal()) {
            return;
        }

        $type = KnowledgeEventType::tryFromLoose($observation->eventType);
        if ($type === null) {
            $type = match ($watcher->trigger_type) {
                WatcherTriggerType::GmailMessage => KnowledgeEventType::EmailReceived,
                WatcherTriggerType::CalendarEvent => KnowledgeEventType::CalendarEvent,
                WatcherTriggerType::GithubEvent => KnowledgeEventType::GithubCommitSeen,
                default => null,
            };
        }

        if ($type === null) {
            return;
        }

        try {
            $this->ingestion->recordEvent(
                $user,
                $type,
                $observation->title,
                new KnowledgeSourceRef(
                    type: WatcherSupport::knowledgeSource(),
                    fingerprint: WatcherSupport::fingerprint('knowledge-bridge', (string) $watcher->id, $observation->fingerprint),
                    confidence: KnowledgeConfidence::deterministic(),
                    projectId: $watcher->project_id,
                    observedAt: $observation->occurredAt,
                ),
            );
        } catch (Throwable) {
        }
    }
}
