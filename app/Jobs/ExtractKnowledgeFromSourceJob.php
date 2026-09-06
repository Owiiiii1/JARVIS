<?php

namespace App\Jobs;

use App\Enums\AsyncFailureCategory;
use App\Enums\KnowledgeAnalysisRunStatus;
use App\Enums\KnowledgeSourceType;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\ConversationSummary;
use App\Models\KnowledgeAnalysisRun;
use App\Models\Memory;
use App\Models\User;
use App\Services\Knowledge\KnowledgeExtractor;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractKnowledgeFromSourceJob implements ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $userId,
        public readonly string $sourceType,
        public readonly string $sourceFingerprint,
        public readonly int $sourceId,
        public readonly ?int $conversationId = null,
    ) {
        $this->onQueue((string) config('knowledge.queue', 'memory'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function handle(KnowledgeExtractor $extractor): void
    {
        $existing = $this->existingRun();

        if ($existing !== null && $existing->status === KnowledgeAnalysisRunStatus::Completed) {
            return;
        }

        $user = User::query()->find($this->userId);
        $type = KnowledgeSourceType::tryFrom($this->sourceType);

        if ($user === null || $type === null) {
            $this->markUnavailable(AsyncFailureCategory::MissingSource);

            return;
        }

        $memory = $type === KnowledgeSourceType::Memory
            ? Memory::query()->where('user_id', $this->userId)->whereKey($this->sourceId)->first()
            : null;
        $summary = $type === KnowledgeSourceType::Summary
            ? ConversationSummary::query()->where('user_id', $this->userId)->whereKey($this->sourceId)->first()
            : null;

        if ($type === KnowledgeSourceType::Memory && $memory === null) {
            $this->markUnavailable(AsyncFailureCategory::StaleSource);

            return;
        }

        if ($type === KnowledgeSourceType::Summary && $summary === null) {
            $this->markUnavailable(AsyncFailureCategory::StaleSource);

            return;
        }

        $run = KnowledgeAnalysisRun::query()->firstOrNew([
            'user_id' => $this->userId,
            'source_fingerprint' => $this->sourceFingerprint,
        ]);
        $run->fill([
            'source_type' => $type,
            'status' => KnowledgeAnalysisRunStatus::Processing,
            'attempts' => (int) $run->attempts + 1,
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ]);
        $run->save();

        try {
            $result = $type === KnowledgeSourceType::Summary && $summary !== null
                ? $extractor->extractFromSummary($user, $summary)
                : $extractor->extractFromMemory($user, $memory);

            $run->forceFill([
                'status' => KnowledgeAnalysisRunStatus::Completed,
                'completed_at' => now(),
                'last_error' => null,
                'metadata' => $result['stats'],
            ])->save();

            Log::info('knowledge extraction completed', [
                'job' => self::class,
                'run_id' => $run->id,
                'user_id' => $this->userId,
                'source_type' => $this->sourceType,
                'provider' => $result['provider'],
                'model' => $result['model'],
            ]);
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);

            if (! $failure->retryable) {
                $this->failureWriter()->failKnowledgeRun($run, $failure);
                $this->failureWriter()->logFailure('knowledge extraction failed', $failure, $this->logIds($run));

                return;
            }

            $this->failureWriter()->logFailure('knowledge extraction retryable failure', $failure, $this->logIds($run));

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->existingRun();

        if ($run === null || $run->status === KnowledgeAnalysisRunStatus::Completed) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->failKnowledgeRun($run, $failure);
        $this->failureWriter()->logFailure('knowledge extraction job failed', $failure, $this->logIds($run));
    }

    private function existingRun(): ?KnowledgeAnalysisRun
    {
        return KnowledgeAnalysisRun::query()
            ->where('user_id', $this->userId)
            ->where('source_fingerprint', $this->sourceFingerprint)
            ->orderByDesc('id')
            ->first();
    }

    private function markUnavailable(AsyncFailureCategory $category): void
    {
        $run = $this->existingRun();

        if ($run === null) {
            $run = KnowledgeAnalysisRun::query()->create([
                'user_id' => $this->userId,
                'source_type' => KnowledgeSourceType::tryFrom($this->sourceType) ?? KnowledgeSourceType::Memory,
                'source_fingerprint' => $this->sourceFingerprint,
                'status' => KnowledgeAnalysisRunStatus::Failed,
                'attempts' => 1,
                'started_at' => now(),
            ]);
        }

        $failure = $this->classifyFailure(new ClassifiedAsyncException($category, $category->value));
        $this->failureWriter()->failKnowledgeRun($run, $failure);
        $this->failureWriter()->logFailure('knowledge extraction failed', $failure, $this->logIds($run));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function logIds(KnowledgeAnalysisRun $run): array
    {
        return [
            'job' => self::class,
            'run_id' => $run->id,
            'user_id' => $this->userId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
        ];
    }
}
