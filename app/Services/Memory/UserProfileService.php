<?php

namespace App\Services\Memory;

use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use App\Models\Memory;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;

final class UserProfileService
{
    public function maybeUpdate(User $user, int $memoryChanges): void
    {
        $threshold = (int) config('memory.profile_memory_change_threshold');

        if ($memoryChanges < $threshold && ! $this->missingProfileWithEnoughMemories($user)) {
            return;
        }

        $this->rebuild($user);
    }

    public function rebuild(User $user): ?UserProfile
    {
        $minConfidence = (float) config('memory.retrieval.min_confidence');

        $memories = Memory::query()
            ->where('user_id', $user->id)
            ->where('scope', MemoryScope::Personal)
            ->where('status', MemoryStatus::Active)
            ->where('confidence', '>=', $minConfidence)
            ->where(function ($builder): void {
                $builder->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->orderByDesc('confidence')
            ->limit(25)
            ->pluck('content')
            ->all();

        if ($memories === []) {
            return null;
        }

        $configuration = $this->resolver->resolveAnalysis();
        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: (string) $configuration->system_prompt,
            messages: [new AiChatMessage('user', $this->prompts->build($memories))],
            parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
        ));

        $payload = StructuredJsonParser::objectFromText($response->text);
        $summary = trim((string) ($payload['summary'] ?? ''));

        if ($summary === '') {
            return null;
        }

        $profile = UserProfile::query()->firstOrNew(['user_id' => $user->id]);
        $profile->fill([
            'summary' => mb_substr($summary, 0, 4000),
            'updated_from_memory_at' => now(),
        ]);
        $profile->save();

        return $profile;
    }

    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
        private readonly ProfilePromptBuilder $prompts,
    ) {}

    private function missingProfileWithEnoughMemories(User $user): bool
    {
        if (UserProfile::query()->where('user_id', $user->id)->whereNotNull('summary')->exists()) {
            return false;
        }

        $minConfidence = (float) config('memory.retrieval.min_confidence');

        return Memory::query()
            ->where('user_id', $user->id)
            ->where('scope', MemoryScope::Personal)
            ->where('status', MemoryStatus::Active)
            ->where('confidence', '>=', $minConfidence)
            ->count() >= (int) config('memory.profile_memory_change_threshold');
    }
}
