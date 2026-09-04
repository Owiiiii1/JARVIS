<?php

namespace App\Services\WebResearch\DTO;

final readonly class WebSearchResultSet
{
    /**
     * @param  list<WebSearchHit>  $results
     */
    public function __construct(
        public string $query,
        public array $results,
        public string $provider,
        public bool $truncated = false,
    ) {}

    /**
     * @return list<WebSourceReference>
     */
    public function sources(): array
    {
        return array_map(
            static fn (WebSearchHit $hit): WebSourceReference => $hit->source(),
            $this->results,
        );
    }
}
