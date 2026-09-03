<?php

namespace App\Services\Ai;

use App\Enums\AiRoleKey;
use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Services\Conversations\ConversationContextBuilder;
use Illuminate\Validation\ValidationException;

final class AiRoleSettingsService
{
    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function payload(): array
    {
        $this->ensureRolesExist();

        return AiRoleSetting::query()
            ->get()
            ->sortBy(fn (AiRoleSetting $setting): int => match ($setting->roleKey()) {
                AiRoleKey::OwnerConversation => 1,
                AiRoleKey::OwnerAnalysis => 2,
                AiRoleKey::UserConversation => 3,
            })
            ->values()
            ->map(fn (AiRoleSetting $setting): array => [
                'role_key' => $setting->roleKey()->value,
                'label' => $setting->roleKey()->label(),
                'provider' => $setting->provider,
                'model' => $setting->model,
                'system_prompt' => $setting->system_prompt,
                'parameters' => $setting->parameters ?? [],
                'is_enabled' => (bool) $setting->is_enabled,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AiRoleKey $role, array $data): AiRoleSetting
    {
        $this->ensureRolesExist();

        $setting = AiRoleSetting::query()
            ->where('role_key', $role->value)
            ->firstOrFail();

        $provider = filled($data['provider'] ?? null) ? (string) $data['provider'] : null;
        $model = filled($data['model'] ?? null) ? (string) $data['model'] : null;
        $enabled = (bool) ($data['is_enabled'] ?? false);
        $parameters = $this->normalizeParameters($data['parameters'] ?? null);

        if ($enabled) {
            $this->assertCanEnable($provider, $model);
        }

        $setting->fill([
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => (string) $data['system_prompt'],
            'parameters' => $parameters,
            'is_enabled' => $enabled,
        ])->save();

        return $setting;
    }

    private function assertCanEnable(?string $provider, ?string $model): void
    {
        if ($provider === null || $model === null) {
            throw ValidationException::withMessages([
                'ai' => 'Provider and model are required to enable an AI configuration.',
            ]);
        }

        try {
            $supportsChat = $this->providers->supportsChat($provider);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'ai' => 'Unknown AI provider.',
            ]);
        }

        if (! $supportsChat) {
            throw ValidationException::withMessages([
                'ai' => 'Chat is not implemented for this provider.',
            ]);
        }

        $credential = AiProviderSetting::query()
            ->where('provider', $provider)
            ->first();

        if ($credential === null || ! $credential->is_connected || ! filled($credential->api_key)) {
            throw ValidationException::withMessages([
                'ai' => 'Connect the provider credentials before enabling this AI configuration.',
            ]);
        }

        $available = collect($credential->available_models ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if (! in_array($model, $available, true)) {
            throw ValidationException::withMessages([
                'ai' => 'Selected model is not available for this provider. Re-check connection.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeParameters(mixed $parameters): array
    {
        if (! is_array($parameters)) {
            $parameters = [];
        }

        $normalized = [
            'recent_message_limit' => max(
                ConversationContextBuilder::MIN_RECENT_LIMIT,
                min(
                    ConversationContextBuilder::MAX_RECENT_LIMIT,
                    (int) ($parameters['recent_message_limit'] ?? ConversationContextBuilder::DEFAULT_RECENT_LIMIT)
                )
            ),
        ];

        if (array_key_exists('temperature', $parameters) && $parameters['temperature'] !== null && $parameters['temperature'] !== '') {
            $temperature = (float) $parameters['temperature'];
            $normalized['temperature'] = max(0, min(2, $temperature));
        }

        if (array_key_exists('max_tokens', $parameters) && $parameters['max_tokens'] !== null && $parameters['max_tokens'] !== '') {
            $normalized['max_tokens'] = max(1, min(8192, (int) $parameters['max_tokens']));
        }

        return $normalized;
    }

    private function ensureRolesExist(): void
    {
        foreach (AiRoleKey::cases() as $role) {
            AiRoleSetting::query()->firstOrCreate(
                ['role_key' => $role->value],
                [
                    'system_prompt' => DefaultRolePrompts::for($role),
                    'parameters' => ['recent_message_limit' => 30],
                    'is_enabled' => false,
                ],
            );
        }
    }
}
