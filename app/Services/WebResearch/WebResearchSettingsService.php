<?php

namespace App\Services\WebResearch;

use App\Enums\WebResearchProvider;
use App\Models\AiProviderSetting;
use App\Models\WebResearchSetting;
use App\Services\WebResearch\DTO\WebResearchEffectiveSettings;
use Illuminate\Support\Facades\Schema;

final class WebResearchSettingsService
{
    public function record(): ?WebResearchSetting
    {
        if (! $this->tableReady()) {
            return null;
        }

        return WebResearchSetting::query()->first();
    }

    public function ensureRecord(): WebResearchSetting
    {
        $record = $this->record();

        if ($record !== null) {
            return $record;
        }

        $effective = $this->fromConfig();

        return WebResearchSetting::query()->create([
            'enabled' => $effective->enabled,
            'provider' => $effective->provider,
            'max_search_results' => $effective->maxSearchResults,
            'max_searches_per_turn' => $effective->maxSearchesPerTurn,
            'max_fetches_per_turn' => $effective->maxFetchesPerTurn,
            'max_page_chars' => $effective->maxPageChars,
            'max_total_web_chars' => $effective->maxTotalWebChars,
            'fetch_web_page_enabled' => $effective->fetchWebPageEnabled,
            'timeout_seconds' => $effective->timeoutSeconds,
            'default_recency_days' => $effective->defaultRecencyDays,
            'tavily_api_key' => null,
        ]);
    }

    public function effective(): WebResearchEffectiveSettings
    {
        $record = $this->record();

        if ($record === null) {
            return $this->fromConfig();
        }

        return $this->clamp(new WebResearchEffectiveSettings(
            enabled: (bool) $record->enabled,
            provider: $record->provider instanceof WebResearchProvider
                ? $record->provider
                : WebResearchProvider::normalize($record->provider),
            maxSearchResults: (int) $record->max_search_results,
            defaultSearchResults: (int) config('web_research.default_search_results', 5),
            maxSearchesPerTurn: (int) $record->max_searches_per_turn,
            maxFetchesPerTurn: (int) $record->max_fetches_per_turn,
            maxPageChars: (int) $record->max_page_chars,
            maxTotalWebChars: (int) $record->max_total_web_chars,
            fetchWebPageEnabled: (bool) $record->fetch_web_page_enabled,
            timeoutSeconds: (int) $record->timeout_seconds,
            connectTimeoutSeconds: max(1, (int) config('web_research.connect_timeout', 5)),
            maxSnippetChars: max(80, (int) config('web_research.max_snippet_chars', 280)),
            defaultRecencyDays: $this->nullableRecency($record->default_recency_days),
        ));
    }

    /**
     * Tavily key: persisted Admin value, then WEB_SEARCH_API_KEY / config fallback.
     */
    public function tavilyApiKey(): string
    {
        $record = $this->record();
        $stored = $record !== null ? trim((string) $record->tavily_api_key) : '';

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('web_research.tavily.api_key', ''));
    }

    public function tavilyKeySource(): ?string
    {
        $record = $this->record();
        $stored = $record !== null ? trim((string) $record->tavily_api_key) : '';

        if ($stored !== '') {
            return 'admin';
        }

        if (trim((string) config('web_research.tavily.api_key', '')) !== '') {
            return 'env';
        }

        return null;
    }

    public function geminiConfigured(): bool
    {
        $credential = AiProviderSetting::query()->where('provider', 'gemini')->first();

        return $credential !== null && $credential->is_connected && filled($credential->api_key);
    }

    public function providerConfigured(?WebResearchProvider $provider = null): bool
    {
        $provider ??= $this->effective()->provider;

        return match ($provider) {
            WebResearchProvider::GeminiGoogle => $this->geminiConfigured(),
            WebResearchProvider::Tavily => $this->tavilyApiKey() !== '',
            WebResearchProvider::Disabled => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $effective = $this->effective();
        $status = $this->statusCode($effective);

        return [
            'enabled' => $effective->enabled,
            'provider' => $effective->provider->value,
            'max_search_results' => $effective->maxSearchResults,
            'max_searches_per_turn' => $effective->maxSearchesPerTurn,
            'max_fetches_per_turn' => $effective->maxFetchesPerTurn,
            'max_page_chars' => $effective->maxPageChars,
            'max_total_web_chars' => $effective->maxTotalWebChars,
            'fetch_web_page_enabled' => $effective->fetchWebPageEnabled,
            'timeout_seconds' => $effective->timeoutSeconds,
            'default_recency_days' => $effective->defaultRecencyDays,
            'gemini_configured' => $this->geminiConfigured(),
            'google_search_available' => $this->geminiConfigured(),
            'tavily_configured' => $this->tavilyApiKey() !== '',
            'tavily_key_source' => $this->tavilyKeySource(),
            'provider_configured' => $this->providerConfigured($effective->provider),
            'status' => $status,
            'status_label' => $this->statusLabel($status, $effective->provider),
            'active_provider_label' => $effective->provider->label(),
            'runtime_enabled' => $effective->isRuntimeEnabled(),
            'fetch_effective' => $effective->isFetchEnabled(),
            'ceilings' => $this->ceilings(),
            'floors' => $this->floors(),
            'updated_at' => optional($this->record()?->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Read-only Workspace integrations row. No secrets, no limit editor.
     *
     * @return array<string, mixed>
     */
    public function workspaceSummary(): array
    {
        $effective = $this->effective();
        $status = $this->statusCode($effective);
        $state = match ($status) {
            'ready' => 'connected',
            'not_configured' => 'incomplete',
            default => 'disabled',
        };

        return [
            'provider' => 'web_research',
            'display_name' => $effective->isRuntimeEnabled()
                ? $effective->provider->workspaceLabel()
                : WebResearchProvider::Disabled->workspaceLabel(),
            'state' => $state,
            'label' => $this->statusLabel($status, $effective->provider),
            'account_label' => $this->statusLabel($status, $effective->provider),
            'configured' => $status === 'ready',
            'capabilities' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): WebResearchSetting
    {
        $record = $this->ensureRecord();
        $provider = WebResearchProvider::normalize($data['provider'] ?? $record->provider);

        $record->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'provider' => $provider,
            'max_search_results' => $this->clampInt((int) ($data['max_search_results'] ?? $record->max_search_results), 'max_search_results'),
            'max_searches_per_turn' => $this->clampInt((int) ($data['max_searches_per_turn'] ?? $record->max_searches_per_turn), 'max_searches_per_turn'),
            'max_fetches_per_turn' => $this->clampInt((int) ($data['max_fetches_per_turn'] ?? $record->max_fetches_per_turn), 'max_fetches_per_turn'),
            'max_page_chars' => $this->clampInt((int) ($data['max_page_chars'] ?? $record->max_page_chars), 'max_page_chars'),
            'max_total_web_chars' => $this->clampInt((int) ($data['max_total_web_chars'] ?? $record->max_total_web_chars), 'max_total_web_chars'),
            'fetch_web_page_enabled' => (bool) ($data['fetch_web_page_enabled'] ?? false),
            'timeout_seconds' => $this->clampInt((int) ($data['timeout_seconds'] ?? $record->timeout_seconds), 'timeout_seconds'),
            'default_recency_days' => $this->nullableRecency($data['default_recency_days'] ?? null),
        ])->save();

        return $record->refresh();
    }

    public function setTavilyApiKey(string $key): void
    {
        $record = $this->ensureRecord();
        $record->tavily_api_key = trim($key);
        $record->save();
    }

    public function clearTavilyApiKey(): void
    {
        $record = $this->ensureRecord();
        $record->tavily_api_key = null;
        $record->save();
    }

    /**
     * @return array<string, int>
     */
    public function ceilings(): array
    {
        return [
            'max_search_results' => (int) config('web_research.ceilings.max_search_results', 20),
            'max_searches_per_turn' => (int) config('web_research.ceilings.max_searches_per_turn', 10),
            'max_fetches_per_turn' => (int) config('web_research.ceilings.max_fetches_per_turn', 10),
            'max_page_chars' => (int) config('web_research.ceilings.max_page_chars', 20000),
            'max_total_web_chars' => (int) config('web_research.ceilings.max_total_web_chars', 40000),
            'timeout_seconds' => (int) config('web_research.ceilings.timeout_seconds', 60),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function floors(): array
    {
        return [
            'max_search_results' => (int) config('web_research.floors.max_search_results', 1),
            'max_searches_per_turn' => (int) config('web_research.floors.max_searches_per_turn', 1),
            'max_fetches_per_turn' => (int) config('web_research.floors.max_fetches_per_turn', 0),
            'max_page_chars' => (int) config('web_research.floors.max_page_chars', 500),
            'max_total_web_chars' => (int) config('web_research.floors.max_total_web_chars', 1000),
            'timeout_seconds' => (int) config('web_research.floors.timeout_seconds', 2),
        ];
    }

    private function fromConfig(): WebResearchEffectiveSettings
    {
        $provider = WebResearchProvider::normalize(config('web_research.provider'));
        $enabled = (bool) config('web_research.enabled', true) && $provider !== WebResearchProvider::Disabled;

        return $this->clamp(new WebResearchEffectiveSettings(
            enabled: $enabled,
            provider: $provider,
            maxSearchResults: (int) config('web_research.max_search_results', 8),
            defaultSearchResults: (int) config('web_research.default_search_results', 5),
            maxSearchesPerTurn: (int) config('web_research.max_searches_per_turn', 2),
            maxFetchesPerTurn: (int) config('web_research.max_fetches_per_turn', 4),
            maxPageChars: (int) config('web_research.max_page_chars', 8000),
            maxTotalWebChars: (int) config('web_research.max_total_web_chars', 18000),
            fetchWebPageEnabled: (bool) config('web_research.fetch_web_page_enabled', true),
            timeoutSeconds: (int) config('web_research.timeout', 12),
            connectTimeoutSeconds: max(1, (int) config('web_research.connect_timeout', 5)),
            maxSnippetChars: max(80, (int) config('web_research.max_snippet_chars', 280)),
            defaultRecencyDays: $this->nullableRecency(config('web_research.default_recency_days')),
        ));
    }

    private function clamp(WebResearchEffectiveSettings $settings): WebResearchEffectiveSettings
    {
        $maxResults = $this->clampInt($settings->maxSearchResults, 'max_search_results');

        return new WebResearchEffectiveSettings(
            enabled: $settings->enabled,
            provider: $settings->provider,
            maxSearchResults: $maxResults,
            defaultSearchResults: max(1, min($maxResults, $settings->defaultSearchResults)),
            maxSearchesPerTurn: $this->clampInt($settings->maxSearchesPerTurn, 'max_searches_per_turn'),
            maxFetchesPerTurn: $this->clampInt($settings->maxFetchesPerTurn, 'max_fetches_per_turn'),
            maxPageChars: $this->clampInt($settings->maxPageChars, 'max_page_chars'),
            maxTotalWebChars: $this->clampInt($settings->maxTotalWebChars, 'max_total_web_chars'),
            fetchWebPageEnabled: $settings->fetchWebPageEnabled,
            timeoutSeconds: $this->clampInt($settings->timeoutSeconds, 'timeout_seconds'),
            connectTimeoutSeconds: max(1, $settings->connectTimeoutSeconds),
            maxSnippetChars: max(80, $settings->maxSnippetChars),
            defaultRecencyDays: $this->nullableRecency($settings->defaultRecencyDays),
        );
    }

    private function clampInt(int $value, string $key): int
    {
        $floors = $this->floors();
        $ceilings = $this->ceilings();
        $floor = $floors[$key] ?? 0;
        $ceiling = $ceilings[$key] ?? $value;

        return max($floor, min($ceiling, $value));
    }

    private function nullableRecency(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $days = (int) $value;

        if ($days < 1) {
            return null;
        }

        return min(365, $days);
    }

    private function statusCode(WebResearchEffectiveSettings $effective): string
    {
        if (! $effective->isRuntimeEnabled()) {
            return 'disabled';
        }

        if (! $this->providerConfigured($effective->provider)) {
            return 'not_configured';
        }

        return 'ready';
    }

    private function statusLabel(string $status, WebResearchProvider $provider): string
    {
        return match ($status) {
            'ready' => 'Ready',
            'not_configured' => $provider === WebResearchProvider::Tavily
                ? 'API key required'
                : 'Gemini not configured',
            default => 'Disabled',
        };
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('web_research_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
