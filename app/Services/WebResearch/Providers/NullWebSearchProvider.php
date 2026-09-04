<?php

namespace App\Services\WebResearch\Providers;

use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;
use App\Services\WebResearch\Exceptions\WebResearchException;

final class NullWebSearchProvider implements WebSearchProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        throw new WebResearchException('web_search_not_configured', 'Web search is not configured.');
    }
}
