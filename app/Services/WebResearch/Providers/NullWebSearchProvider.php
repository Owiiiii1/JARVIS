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
        return 'disabled';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        throw new WebResearchException('web_research_disabled', 'Web research is disabled.');
    }
}
