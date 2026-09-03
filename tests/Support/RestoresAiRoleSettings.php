<?php

namespace Tests\Support;

use App\Enums\AiRoleKey;
use App\Models\AiRoleSetting;

trait RestoresAiRoleSettings
{
    /** @var array<string, array<string, mixed>> */
    private array $aiRoleSettingSnapshots = [];

    private function snapshotAiRoleSettings(): void
    {
        $this->aiRoleSettingSnapshots = AiRoleSetting::query()
            ->get()
            ->mapWithKeys(static fn (AiRoleSetting $setting): array => [
                $setting->roleKey()->value => [
                    'provider' => $setting->provider,
                    'model' => $setting->model,
                    'system_prompt' => $setting->system_prompt,
                    'parameters' => $setting->parameters,
                    'is_enabled' => $setting->is_enabled,
                ],
            ])
            ->all();
    }

    private function restoreAiRoleSettings(): void
    {
        foreach ($this->aiRoleSettingSnapshots as $roleKey => $attributes) {
            $setting = AiRoleSetting::query()->where('role_key', $roleKey)->first();

            if ($setting === null) {
                continue;
            }

            $setting->fill($attributes)->save();
        }

        $this->aiRoleSettingSnapshots = [];
    }

    private function enableRoleForTests(AiRoleKey $role, string $provider = 'openai', string $model = 'fake-model'): void
    {
        AiRoleSetting::query()->where('role_key', $role->value)->update([
            'provider' => $provider,
            'model' => $model,
            'is_enabled' => true,
        ]);
    }
}
