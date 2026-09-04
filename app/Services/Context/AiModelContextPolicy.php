<?php

namespace App\Services\Context;

use App\Models\AiRoleSetting;

final class AiModelContextPolicy
{
    /**
     * @return array{max_context_tokens: int, reserved_output_tokens: int, input_budget: int}
     */
    public function for(AiRoleSetting $configuration): array
    {
        return $this->resolve((string) $configuration->provider, (string) $configuration->model);
    }

    /**
     * @return array{max_context_tokens: int, reserved_output_tokens: int, input_budget: int}
     */
    public function resolve(string $provider, string $model): array
    {
        $default = (array) config('ai_model_context.default', []);
        $max = (int) ($default['max_context_tokens'] ?? 32000);
        $reserve = (int) ($default['reserved_output_tokens'] ?? 2048);

        $providerKey = strtolower(trim($provider));
        $providers = (array) config('ai_model_context.providers', []);
        if ($providerKey !== '' && isset($providers[$providerKey]) && is_array($providers[$providerKey])) {
            $max = (int) ($providers[$providerKey]['max_context_tokens'] ?? $max);
            $reserve = (int) ($providers[$providerKey]['reserved_output_tokens'] ?? $reserve);
        }

        $modelKey = strtolower(trim($model));
        $models = (array) config('ai_model_context.models', []);

        if ($modelKey !== '' && isset($models[$modelKey]) && is_array($models[$modelKey])) {
            $max = (int) ($models[$modelKey]['max_context_tokens'] ?? $max);
            $reserve = (int) ($models[$modelKey]['reserved_output_tokens'] ?? $reserve);
        } else {
            foreach ($models as $name => $row) {
                if (! is_array($row) || ! is_string($name)) {
                    continue;
                }
                if ($modelKey !== '' && str_starts_with($modelKey, strtolower($name))) {
                    $max = (int) ($row['max_context_tokens'] ?? $max);
                    $reserve = (int) ($row['reserved_output_tokens'] ?? $reserve);
                    break;
                }
            }
        }

        $max = max(4096, $max);
        $reserve = max(256, min((int) floor($max / 4), $reserve));
        $margin = max(64, (int) config('context_budget.safety_margin_tokens', 512));
        $input = max(1024, $max - $reserve - $margin);

        return [
            'max_context_tokens' => $max,
            'reserved_output_tokens' => $reserve,
            'input_budget' => $input,
        ];
    }
}
