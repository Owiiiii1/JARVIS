<?php

namespace App\Services\Reliability;

use App\Enums\AttachmentSummaryStatus;
use App\Enums\ConversationKind;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupStatus;
use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\AnalyzeTelegramGroupRangeJob;
use App\Jobs\SummarizeMessageAttachmentJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\MemoryAnalysisRun;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use Carbon\CarbonImmutable;

final class FailedAsyncRetryService
{
    public function __construct(
        private readonly AsyncFailureClassifier $classifier,
    ) {}

    /**
     * @return array{eligible: int, skipped: int, dispatched: int}
     */
    public function retryMemory(int $limit, int $hours, ?string $category, bool $execute): array
    {
        $runs = MemoryAnalysisRun::query()
            ->where('status', MemoryAnalysisRunStatus::Failed)
            ->where('updated_at', '>=', CarbonImmutable::now()->subHours(max(1, $hours)))
            ->orderBy('id')
            ->limit(max(1, $limit) * 10)
            ->get();

        $eligible = 0;
        $skipped = 0;
        $dispatched = 0;

        foreach ($runs as $run) {
            if ($eligible >= $limit) {
                break;
            }

            $failure = $this->classifier->classifyStored($run->last_error, $run->metadata);

            if ($category !== null && $failure->category->value !== $category) {
                $skipped++;

                continue;
            }

            if (! $this->isEligible($failure)) {
                $skipped++;

                continue;
            }

            $conversation = Conversation::query()->find($run->conversation_id);

            if ($conversation === null || $conversation->kind !== ConversationKind::Personal) {
                $skipped++;

                continue;
            }

            $from = $run->from_message_id !== null ? Message::query()->find($run->from_message_id) : null;
            $to = $run->to_message_id !== null ? Message::query()->find($run->to_message_id) : null;

            if ($run->type === MemoryAnalysisRunType::Turn && ($from === null || $to === null)) {
                $skipped++;

                continue;
            }

            $eligible++;

            if (! $execute) {
                continue;
            }

            if ($run->type === MemoryAnalysisRunType::Summary) {
                UpdateConversationSummaryJob::dispatch((int) $run->user_id, (int) $run->conversation_id, true)
                    ->onQueue((string) config('memory.queue'));
            } else {
                AnalyzeConversationTurnJob::dispatch(
                    (int) $run->user_id,
                    (int) $run->conversation_id,
                    (int) $run->from_message_id,
                    (int) $run->to_message_id,
                )->onQueue((string) config('memory.queue'));
            }

            $dispatched++;
        }

        return [
            'eligible' => $eligible,
            'skipped' => $skipped,
            'dispatched' => $execute ? $dispatched : 0,
        ];
    }

    /**
     * @return array{eligible: int, skipped: int, dispatched: int}
     */
    public function retryGroups(int $limit, int $hours, ?string $category, bool $execute): array
    {
        $runs = TelegramGroupAnalysisRun::query()
            ->where('status', TelegramGroupAnalysisRunStatus::Failed)
            ->where('updated_at', '>=', CarbonImmutable::now()->subHours(max(1, $hours)))
            ->orderBy('id')
            ->limit(max(1, $limit) * 10)
            ->get();

        $eligible = 0;
        $skipped = 0;
        $dispatched = 0;

        foreach ($runs as $run) {
            if ($eligible >= $limit) {
                break;
            }

            $failure = $this->classifier->classifyStored($run->last_error, $run->metadata);

            if ($category !== null && $failure->category->value !== $category) {
                $skipped++;

                continue;
            }

            if (! $this->isEligible($failure)) {
                $skipped++;

                continue;
            }

            $group = TelegramGroup::query()->find($run->telegram_group_id);

            if ($group === null || $group->status === TelegramGroupStatus::Left) {
                $skipped++;

                continue;
            }

            $eligible++;

            if (! $execute) {
                continue;
            }

            $run->forceFill([
                'status' => TelegramGroupAnalysisRunStatus::Queued,
                'last_error' => null,
            ])->save();

            AnalyzeTelegramGroupRangeJob::dispatch((int) $run->id)
                ->onQueue((string) config('group_analysis.queue'));

            $dispatched++;
        }

        return [
            'eligible' => $eligible,
            'skipped' => $skipped,
            'dispatched' => $execute ? $dispatched : 0,
        ];
    }

    /**
     * @return array{eligible: int, skipped: int, dispatched: int}
     */
    public function retryAttachments(int $limit, int $hours, ?string $category, bool $execute): array
    {
        $rows = MessageAttachment::query()
            ->where('summary_status', AttachmentSummaryStatus::Failed)
            ->whereNull('purged_at')
            ->where('updated_at', '>=', CarbonImmutable::now()->subHours(max(1, $hours)))
            ->orderBy('id')
            ->limit(max(1, $limit) * 10)
            ->get();

        $eligible = 0;
        $skipped = 0;
        $dispatched = 0;

        foreach ($rows as $attachment) {
            if ($eligible >= $limit) {
                break;
            }

            $failure = $this->classifier->classifyStored(null, $attachment->metadata);

            if ($category !== null && $failure->category->value !== $category) {
                $skipped++;

                continue;
            }

            if (! $this->isEligible($failure)) {
                $skipped++;

                continue;
            }

            if ($attachment->storage_path === '' || Message::query()->find($attachment->message_id) === null) {
                $skipped++;

                continue;
            }

            $eligible++;

            if (! $execute) {
                continue;
            }

            $attachment->forceFill([
                'summary_status' => AttachmentSummaryStatus::Pending,
            ])->save();

            SummarizeMessageAttachmentJob::dispatch((int) $attachment->id)
                ->onQueue(ChatAttachmentConfig::summaryQueue());

            $dispatched++;
        }

        return [
            'eligible' => $eligible,
            'skipped' => $skipped,
            'dispatched' => $execute ? $dispatched : 0,
        ];
    }

    private function isEligible(AsyncFailure $failure): bool
    {
        if ($failure->code === 'stale_running') {
            return true;
        }

        return $failure->retryable;
    }
}
