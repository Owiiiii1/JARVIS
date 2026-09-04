<?php

namespace App\Services\WebResearch\DTO;

final readonly class WebSourceReference
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $domain,
        public ?string $publishedAt = null,
        public ?string $fetchedAt = null,
    ) {}

    /**
     * @return array{id: string, title: string, url: string, domain: string, published_at: string|null, fetched_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'domain' => $this->domain,
            'published_at' => $this->publishedAt,
            'fetched_at' => $this->fetchedAt,
        ];
    }
}
