<?php

namespace App\Services\WebResearch;

use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;
use App\Services\WebResearch\Exceptions\WebResearchException;

final class WebSearchManager
{
    public function __construct(
        private readonly WebSearchProvider $provider,
    ) {}

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        if (! $this->provider->isConfigured()) {
            throw new WebResearchException('web_search_not_configured', 'Web search is not configured.');
        }

        return $this->provider->search($query);
    }
}
