<?php

namespace App\Console\Commands;

use App\Enums\ConversationKind;
use App\Enums\KnowledgeSourceType;
use App\Jobs\ExtractKnowledgeFromSourceJob;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\User;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jarvis:knowledge:backfill {--user= : Internal user id} {--conversation= : Internal conversation id} {--project= : Internal project id} {--limit=20 : Max sources} {--since= : ISO datetime lower bound} {--dry-run=1 : Show work without dispatching (default)}')]
#[Description('Bounded knowledge extraction backfill. Dry-run by default. Does not scan production automatically.')]
class KnowledgeBackfillCommand extends Command
{
    public function handle(): int
    {
        $userId = $this->option('user') !== null && $this->option('user') !== '' ? (int) $this->option('user') : null;
        $conversationId = $this->option('conversation') !== null && $this->option('conversation') !== '' ? (int) $this->option('conversation') : null;
        $projectId = $this->option('project') !== null && $this->option('project') !== '' ? (int) $this->option('project') : null;
        $limit = max(1, (int) $this->option('limit'));
        $since = $this->option('since') !== null && $this->option('since') !== '' ? (string) $this->option('since') : null;
        $dryRun = $this->option('dry-run') === null
            || $this->option('dry-run') === true
            || $this->option('dry-run') === '1'
            || $this->option('dry-run') === 'true';

        if ($this->option('dry-run') === '0' || $this->option('dry-run') === 'false') {
            $dryRun = false;
        }

        if ($userId === null) {
            $this->error('--user is required.');

            return self::FAILURE;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $memories = Memory::query()
            ->where('user_id', $userId)
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $summaries = ConversationSummary::query()
            ->where('user_id', $userId)
            ->when($conversationId, fn ($query) => $query->where('conversation_id', $conversationId))
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($conversationId !== null) {
            $owned = Conversation::query()
                ->where('user_id', $userId)
                ->where('kind', ConversationKind::Personal)
                ->whereKey($conversationId)
                ->exists();

            if (! $owned) {
                $this->error('Conversation is out of scope.');

                return self::FAILURE;
            }
        }

        $queued = 0;

        foreach ($memories as $memory) {
            $fingerprint = KnowledgeSourceRef::hash('memory', (string) $memory->id, (string) $memory->updated_at);
            $this->line("memory id={$memory->id} fingerprint={$fingerprint}");

            if (! $dryRun) {
                ExtractKnowledgeFromSourceJob::dispatch($userId, KnowledgeSourceType::Memory->value, $fingerprint, (int) $memory->id, $conversationId);
                $queued++;
            }
        }

        foreach ($summaries as $summary) {
            $fingerprint = KnowledgeSourceRef::hash('summary', (string) $summary->id, (string) $summary->version);
            $this->line("summary id={$summary->id} fingerprint={$fingerprint}");

            if (! $dryRun) {
                ExtractKnowledgeFromSourceJob::dispatch($userId, KnowledgeSourceType::Summary->value, $fingerprint, (int) $summary->id, (int) $summary->conversation_id);
                $queued++;
            }
        }

        if ($projectId !== null) {
            $this->line("project scope={$projectId} (deterministic domain remains canonical; no historical LLM scan)");
        }

        $this->info($dryRun ? 'Dry-run only. No jobs dispatched.' : "Queued {$queued} extraction jobs.");

        return self::SUCCESS;
    }
}
