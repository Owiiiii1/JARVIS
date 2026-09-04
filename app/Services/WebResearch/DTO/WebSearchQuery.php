<?php

namespace App\Services\WebResearch\DTO;

final readonly class WebSearchQuery
{
    /**
     * @param  list<string>  $domains
     * @param  list<string>  $excludeDomains
     */
    public function __construct(
        public string $query,
        public int $maxResults = 5,
        public ?int $recencyDays = null,
        public array $domains = [],
        public array $excludeDomains = [],
    ) {}
}
