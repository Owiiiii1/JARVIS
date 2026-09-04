<?php

namespace App\Services\WebResearch;

use App\Enums\WebResearchProvider;
use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;
use App\Services\WebResearch\Exceptions\WebResearchException;
use App\Services\WebResearch\Providers\GeminiGoogleSearchProvider;
use App\Services\WebResearch\Providers\NullWebSearchProvider;
use App\Services\WebResearch\Providers\TavilyWebSearchProvider;

final class WebSearchManager
{
    public function __construct(
        private readonly WebResearchSettingsService $settings,
        private readonly GeminiGoogleSearchProvider $gemini,
        private readonly TavilyWebSearchProvider $tavily,
        private readonly NullWebSearchProvider $disabled,
    ) {}

    public function isConfigured(): bool
    {
        $effective = $this->settings->effective();

        if (! $effective->isRuntimeEnabled()) {
            return false;
        }

        return $this->activeProvider()->isConfigured();
    }

    public function providerName(): string
    {
        $effective = $this->settings->effective();

        if (! $effective->isRuntimeEnabled()) {
            return WebResearchProvider::Disabled->value;
        }

        return $this->activeProvider()->name();
    }

    public function activeProvider(): WebSearchProvider
    {
        $effective = $this->settings->effective();

        if (! $effective->isRuntimeEnabled()) {
            return $this->disabled;
        }

        return match ($effective->provider) {
            WebResearchProvider::Tavily => $this->tavily,
            WebResearchProvider::GeminiGoogle => $this->gemini,
            WebResearchProvider::Disabled => $this->disabled,
        };
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        if (! $this->settings->effective()->isRuntimeEnabled()) {
            throw new WebResearchException('web_search_disabled', 'Web research is disabled.');
        }

        $provider = $this->activeProvider();

        if (! $provider->isConfigured()) {
            throw new WebResearchException('web_search_not_configured', 'Web search is not configured.');
        }

        return $provider->search($query);
    }
}
