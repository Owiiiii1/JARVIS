<?php

namespace App\Services\WebResearch\DTO;

final readonly class WebSearchHit
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $domain,
        public string $snippet,
        public ?string $publishedAt = null,
        public ?float $score = null,
        public ?int $rank = null,
        public string $sourceType = 'web',
        public bool $truncated = false,
    ) {}

    public function source(): WebSourceReference
    {
        return new WebSourceReference(
            id: $this->id,
            title: $this->title,
            url: $this->url,
            domain: $this->domain,
            publishedAt: $this->publishedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'domain' => $this->domain,
            'snippet' => $this->snippet,
            'published_at' => $this->publishedAt,
            'score' => $this->score,
            'rank' => $this->rank,
            'source_type' => $this->sourceType,
            'truncated' => $this->truncated,
        ];
    }
}
