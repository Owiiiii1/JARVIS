<?php

namespace App\Services\WebResearch\DTO;

use App\Enums\WebResearchProvider;

final readonly class WebResearchEffectiveSettings
{
    public function __construct(
        public bool $enabled,
        public WebResearchProvider $provider,
        public int $maxSearchResults,
        public int $defaultSearchResults,
        public int $maxSearchesPerTurn,
        public int $maxFetchesPerTurn,
        public int $maxPageChars,
        public int $maxTotalWebChars,
        public bool $fetchWebPageEnabled,
        public int $timeoutSeconds,
        public int $connectTimeoutSeconds,
        public int $maxSnippetChars,
        public ?int $defaultRecencyDays,
    ) {}

    public function isRuntimeEnabled(): bool
    {
        return $this->enabled && $this->provider !== WebResearchProvider::Disabled;
    }

    public function isFetchEnabled(): bool
    {
        return $this->isRuntimeEnabled() && $this->fetchWebPageEnabled;
    }
}
