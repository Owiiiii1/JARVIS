<?php

namespace App\Services\WebResearch\Contracts;

use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;

interface WebSearchProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function search(WebSearchQuery $query): WebSearchResultSet;
}
