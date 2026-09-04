<?php

namespace App\Services\Workspace;

use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryStatus;
use App\Enums\ProjectStatus;
use App\Enums\TopicStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryAnalysisRun;
use App\Models\Topic;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectService;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;
use App\Services\WebResearch\WebResearchSettingsService;

final class OwnerWorkspaceContextService
{
    public const PROJECT_LIMIT = 12;

    public const REMINDER_LIMIT = 8;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ReminderService $reminders,
        private readonly IntegrationRegistry $integrations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compact(User $user, Conversation $conversation): array
    {
        return [
            'projects' => $this->projectsSummary($user, $conversation),
            'reminders' => $this->remindersSummary($user),
            'integrations' => $this->integrationsSummary($user),
            'memory' => $this->memorySummary($user),
            'settings' => [
                'timezone' => $user->timezone,
                'general_prompt' => $user->aiSettings?->general_prompt,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectsSummary(User $user, Conversation $conversation): array
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        try {
            $attachedIds = $conversation->projects()
                ->pluck('projects.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            return $this->projects->listForOwner($user, includeArchived: false)
                ->take(self::PROJECT_LIMIT)
                ->map(static function ($project) use ($attachedIds): array {
                    return [
                        'id' => $project->id,
                        'name' => $project->name,
                        'status' => $project->status instanceof ProjectStatus
                            ? $project->status->value
                            : (string) $project->status,
                        'attached' => in_array((int) $project->id, $attachedIds, true),
                    ];
                })
                ->values()
                ->all();
        } catch (ProjectException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function remindersSummary(User $user): array
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            return [];
        }

        return $this->reminders->listUpcoming($user, self::REMINDER_LIMIT)
            ->map(static fn ($reminder): array => [
                'id' => $reminder->id,
                'text' => $reminder->text,
                'run_at' => optional($reminder->run_at)?->toIso8601String(),
                'timezone' => $reminder->timezone,
                'status' => $reminder->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrationsSummary(User $user): array
    {
        try {
            $statuses = $this->integrations->listForOwner($user);
        } catch (IntegrationException) {
            return [$this->webResearchSummary()];
        }

        return array_values(array_merge(
            [$this->webResearchSummary()],
            array_map(static function ($status): array {
                $capabilities = [];

                foreach ($status->capabilityStates as $capability) {
                    $capabilities[] = [
                        'key' => (string) ($capability['key'] ?? ''),
                        'label' => (string) ($capability['label'] ?? ''),
                        'state' => (string) ($capability['state'] ?? ''),
                    ];
                }

                return [
                    'provider' => $status->provider,
                    'display_name' => $status->displayName,
                    'state' => $status->state->value,
                    'label' => $status->label,
                    'account_label' => $status->accountLabel,
                    'configured' => $status->configured,
                    'capabilities' => $capabilities,
                ];
            }, $statuses),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function webResearchSummary(): array
    {
        return app(WebResearchSettingsService::class)->workspaceSummary();
    }

    /**
     * @return array<string, mixed>
     */
    private function memorySummary(User $user): array
    {
        $facts = Memory::query()
            ->where('user_id', $user->id)
            ->where('status', MemoryStatus::Active)
            ->count();

        $topics = Topic::query()
            ->where('user_id', $user->id)
            ->where('status', TopicStatus::Active)
            ->count();

        $lastRun = MemoryAnalysisRun::query()
            ->where('user_id', $user->id)
            ->where('status', MemoryAnalysisRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        return [
            'facts_count' => $facts,
            'topics_count' => $topics,
            'last_analysis_at' => optional($lastRun?->completed_at)?->toIso8601String(),
        ];
    }
}
