<?php

namespace App\Services\Watchers;

use App\Enums\AsyncFailureCategory;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherOccurrenceStatus;
use App\Enums\WatcherReactionStatus;
use App\Enums\WatcherStatus;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Reliability\AsyncFailureClassifier;
use App\Services\Synthesis\SynthesisCache;
use App\Services\Watchers\DTO\WatcherObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WatcherEvaluationService
{
    public function __construct(
        private readonly WatcherSourceRegistry $sources,
        private readonly WatcherConditionEvaluator $conditions,
        private readonly WatcherAntiSpam $spam,
        private readonly WatcherReactionExecutor $reactions,
        private readonly WatcherKnowledgeBridge $knowledge,
    ) {}

    public function evaluate(Watcher $watcher, bool $force = false): ?WatcherOccurrence
    {
        return DB::transaction(function () use ($watcher, $force): ?WatcherOccurrence {
            $locked = Watcher::query()->whereKey($watcher->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            if (! $force && $locked->status !== WatcherStatus::Active) {
                return null;
            }

            if ($locked->status === WatcherStatus::Paused || $locked->status === WatcherStatus::Cancelled || $locked->status === WatcherStatus::Completed) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            if (! $force && $this->shouldDeferCheck($locked, $now)) {
                return null;
            }

            $user = $locked->user ?? User::query()->find($locked->user_id);
            if ($user === null) {
                return null;
            }

            try {
                $observations = $this->sources->adapterFor($locked)->check($user, $locked);
            } catch (Throwable $exception) {
                $this->markFailure($locked, $exception);

                return null;
            }

            if (! $locked->baselineEstablished()) {
                $this->establishBaseline($locked, $observations, $now);

                return null;
            }

            $cursor = is_array($locked->cursor) ? $locked->cursor : [];
            $seen = is_array($cursor['seen'] ?? null) ? $cursor['seen'] : [];
            $matched = [];
            foreach ($observations as $observation) {
                if (! $observation instanceof WatcherObservation) {
                    continue;
                }
                if (isset($seen[$observation->sourceId]) && (string) $seen[$observation->sourceId] === $observation->fingerprint) {
                    continue;
                }
                $result = $this->conditions->evaluate($locked, $observation);
                if ($result->matched) {
                    $matched[] = ['observation' => $observation, 'match' => $result];
                }
            }

            $this->advanceCursor($locked, $observations, $now, triggered: false);

            if ($matched === []) {
                $this->markHealthy($locked, $now);

                return null;
            }

            $window = max(0, (int) $locked->aggregation_window_seconds);
            if ($window > 0 && count($matched) > 1) {
                return $this->recordAggregated($user, $locked, $matched, $now);
            }

            $first = $matched[0];
            $observation = $first['observation'];
            $reason = $this->spam->shouldSuppress($user, $locked, $observation->fingerprint);
            if ($reason !== null) {
                $this->recordSuppressed($user, $locked, $observation, $reason, $now);
                $this->markHealthy($locked, $now);

                return null;
            }

            $occurrence = $this->persistOccurrence($user, $locked, $observation, $first['match']->reasonCode, $now, WatcherOccurrenceStatus::Matched);
            if ($occurrence === null) {
                return null;
            }

            $this->knowledge->ingest($user, $locked, $observation);
            $this->reactions->execute($user, $locked, $occurrence, $observation);
            $this->afterTrigger($locked, $now);

            return $occurrence->fresh() ?? $occurrence;
        });
    }

    /**
     * @param  list<WatcherObservation>  $observations
     */
    private function establishBaseline(Watcher $watcher, array $observations, CarbonImmutable $now): void
    {
        $seen = [];
        foreach ($observations as $observation) {
            $seen[$observation->sourceId] = $observation->fingerprint;
        }

        $watcher->forceFill([
            'cursor' => [
                'baseline_established' => true,
                'seen' => $seen,
                'established_at' => $now->toIso8601String(),
            ],
            'last_checked_at' => $now,
            'next_check_at' => $now->addSeconds(WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type)),
            'health' => WatcherHealth::Healthy,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_category' => null,
        ])->save();
    }

    /**
     * @param  list<WatcherObservation>  $observations
     */
    private function advanceCursor(Watcher $watcher, array $observations, CarbonImmutable $now, bool $triggered): void
    {
        $cursor = is_array($watcher->cursor) ? $watcher->cursor : [];
        $seen = is_array($cursor['seen'] ?? null) ? $cursor['seen'] : [];
        foreach ($observations as $observation) {
            $seen[$observation->sourceId] = $observation->fingerprint;
        }
        if (count($seen) > 80) {
            $seen = array_slice($seen, -80, preserve_keys: true);
        }
        $cursor['seen'] = $seen;
        $cursor['baseline_established'] = true;
        $watcher->forceFill(['cursor' => $cursor])->save();
    }

    /**
     * @param  list<array{observation: WatcherObservation, match: mixed}>  $matched
     */
    private function recordAggregated(User $user, Watcher $watcher, array $matched, CarbonImmutable $now): ?WatcherOccurrence
    {
        $fingerprint = WatcherSupport::fingerprint('agg', (string) $watcher->id, $now->format('YmdHi'));
        $reason = $this->spam->shouldSuppress($user, $watcher, $fingerprint);
        if ($reason !== null) {
            return null;
        }

        $titles = [];
        foreach (array_slice($matched, 0, 8) as $row) {
            $titles[] = $row['observation']->title;
        }

        $observation = new WatcherObservation(
            sourceType: $matched[0]['observation']->sourceType,
            sourceId: 'batch:'.$now->format('YmdHi'),
            eventType: $matched[0]['observation']->eventType,
            fingerprint: $fingerprint,
            occurredAt: $now,
            title: count($matched).' events: '.WatcherSupport::summary(implode('; ', $titles)),
            metadata: ['count' => count($matched)],
            entityId: $watcher->knowledge_entity_id,
            projectId: $watcher->project_id,
            taskId: $watcher->task_id,
        );

        $occurrence = $this->persistOccurrence($user, $watcher, $observation, 'aggregated', $now, WatcherOccurrenceStatus::Aggregated);
        if ($occurrence === null) {
            return null;
        }

        $this->reactions->execute($user, $watcher, $occurrence, $observation);
        $this->afterTrigger($watcher, $now);

        return $occurrence->fresh() ?? $occurrence;
    }

    private function persistOccurrence(
        User $user,
        Watcher $watcher,
        WatcherObservation $observation,
        string $reason,
        CarbonImmutable $now,
        WatcherOccurrenceStatus $status,
    ): ?WatcherOccurrence {
        $existing = WatcherOccurrence::query()
            ->where('watcher_id', $watcher->id)
            ->where('trigger_fingerprint', $observation->fingerprint)
            ->first();

        if ($existing !== null) {
            return null;
        }

        try {
            return WatcherOccurrence::query()->create([
                'watcher_id' => $watcher->id,
                'user_id' => $user->id,
                'trigger_fingerprint' => $observation->fingerprint,
                'detected_at' => $now,
                'status' => $status,
                'matched_condition' => $reason,
                'reaction_status' => WatcherReactionStatus::Pending,
                'metadata' => WatcherSupport::boundMetadata([
                    'title' => $observation->title,
                    'source_type' => $observation->sourceType,
                    'source_id' => $observation->sourceId,
                    'event_type' => $observation->eventType,
                    'summary' => WatcherSupport::summary($observation->title),
                ]),
            ]);
        } catch (Throwable) {
            return WatcherOccurrence::query()
                ->where('watcher_id', $watcher->id)
                ->where('trigger_fingerprint', $observation->fingerprint)
                ->first();
        }
    }

    private function recordSuppressed(User $user, Watcher $watcher, WatcherObservation $observation, string $reason, CarbonImmutable $now): void
    {
        if ($reason === 'duplicate_fingerprint') {
            return;
        }

        try {
            WatcherOccurrence::query()->create([
                'watcher_id' => $watcher->id,
                'user_id' => $user->id,
                'trigger_fingerprint' => WatcherSupport::fingerprint('suppressed', $observation->fingerprint, $reason, $now->format('YmdH')),
                'detected_at' => $now,
                'status' => WatcherOccurrenceStatus::Suppressed,
                'matched_condition' => $reason,
                'reaction_status' => WatcherReactionStatus::Skipped,
                'metadata' => WatcherSupport::boundMetadata(['reason' => $reason, 'title' => $observation->title]),
            ]);
        } catch (Throwable) {
        }
    }

    private function afterTrigger(Watcher $watcher, CarbonImmutable $now): void
    {
        $watcher->forceFill([
            'last_checked_at' => $now,
            'last_triggered_at' => $now,
            'next_check_at' => $now->addSeconds(WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type)),
            'health' => WatcherHealth::Healthy,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_category' => null,
            'status' => $watcher->mode === WatcherMode::OneShot ? WatcherStatus::Completed : WatcherStatus::Active,
        ])->save();

        try {
            app(SynthesisCache::class)->bumpUserId((int) $watcher->user_id);
        } catch (Throwable) {
        }
    }

    private function markHealthy(Watcher $watcher, CarbonImmutable $now): void
    {
        $watcher->forceFill([
            'last_checked_at' => $now,
            'next_check_at' => $now->addSeconds(WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type)),
            'health' => $watcher->status === WatcherStatus::Active ? WatcherHealth::Healthy : $watcher->health,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_category' => null,
        ])->save();
    }

    private function markFailure(Watcher $watcher, Throwable $exception): void
    {
        $failure = app(AsyncFailureClassifier::class)->classify($exception);
        $now = CarbonImmutable::now('UTC');
        $failures = (int) $watcher->consecutive_failures + 1;
        $auth = $failure->category === AsyncFailureCategory::ProviderAuth;
        $blocked = $auth || (! $failure->retryable && $failures >= (int) config('watchers.limits.permanent_failure_threshold', 5));

        $watcher->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => mb_substr($failure->code, 0, 240),
            'last_error_category' => $failure->category->value,
            'last_checked_at' => $now,
            'health' => $blocked ? WatcherHealth::Blocked : WatcherHealth::Waiting,
            'status' => $blocked && $watcher->mode === WatcherMode::OneShot ? WatcherStatus::Failed : $watcher->status,
            'next_check_at' => $now->addSeconds($blocked
                ? max(60, (int) config('watchers.cadence.blocked_seconds', 21600))
                : min(3600, WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type) * $failures)),
        ])->save();

        if ($blocked && $watcher->blocked_notified_at === null) {
            $user = $watcher->user ?? User::query()->find($watcher->user_id);
            if ($user !== null) {
                $this->reactions->notifyBlocked($user, $watcher);
                $watcher->forceFill(['blocked_notified_at' => $now])->save();
            }
        }
    }

    private function shouldDeferCheck(Watcher $watcher, CarbonImmutable $now): bool
    {
        if ($watcher->last_checked_at === null) {
            return false;
        }

        if ($watcher->health === WatcherHealth::Blocked) {
            $wait = max(60, (int) config('watchers.cadence.blocked_seconds', 21600));

            return $watcher->last_checked_at->addSeconds($wait)->greaterThan($now);
        }

        $failures = (int) $watcher->consecutive_failures;
        if ($failures < 1 || $watcher->health === WatcherHealth::Healthy) {
            return false;
        }

        $backoff = min(3600, WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type) * $failures);

        return $watcher->last_checked_at->addSeconds($backoff)->greaterThan($now);
    }
}
