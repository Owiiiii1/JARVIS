<?php

namespace App\Services\WebResearch\DTO;

final readonly class WebPageDocument
{
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public string $title,
        public string $domain,
        public string $content,
        public int $charCount,
        public bool $truncated,
        public string $fetchedAt,
        public ?string $publishedAt = null,
        public string $contentType = 'text/html',
    ) {}

    public function source(string $id): WebSourceReference
    {
        return new WebSourceReference(
            id: $id,
            title: $this->title !== '' ? $this->title : $this->domain,
            url: $this->finalUrl,
            domain: $this->domain,
            publishedAt: $this->publishedAt,
            fetchedAt: $this->fetchedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested_url' => $this->requestedUrl,
            'final_url' => $this->finalUrl,
            'title' => $this->title,
            'domain' => $this->domain,
            'published_at' => $this->publishedAt,
            'content' => $this->content,
            'char_count' => $this->charCount,
            'truncated' => $this->truncated,
            'fetched_at' => $this->fetchedAt,
        ];
    }
}
