<?php

namespace App\Services\Context;

final class TurnBudgetTracker
{
    public int $searchCount = 0;

    public int $fetchCount = 0;

    public int $webChars = 0;

    public int $toolResultTokens = 0;

    public int $webResultTokens = 0;

    public int $gmailResultTokens = 0;

    public int $githubResultTokens = 0;

    public int $groupResultTokens = 0;

    public int $storageResultTokens = 0;

    /**
     * @var list<array{id: string, title: string, url: string, domain: string, published_at: string|null, fetched_at: string|null}>
     */
    public array $webSources = [];

    public function remainingSearches(): int
    {
        return max(0, (int) config('web_research.max_searches_per_turn', 2) - $this->searchCount);
    }

    public function remainingFetches(): int
    {
        return max(0, (int) config('web_research.max_fetches_per_turn', 4) - $this->fetchCount);
    }

    public function remainingWebChars(): int
    {
        return max(0, (int) config('web_research.max_total_web_chars', 18000) - $this->webChars);
    }

    public function consumeSearch(): bool
    {
        if ($this->remainingSearches() <= 0) {
            return false;
        }

        $this->searchCount++;

        return true;
    }

    public function consumeFetch(): bool
    {
        if ($this->remainingFetches() <= 0) {
            return false;
        }

        $this->fetchCount++;

        return true;
    }

    public function addWebChars(int $chars): bool
    {
        if ($chars > $this->remainingWebChars()) {
            return false;
        }

        $this->webChars += max(0, $chars);

        return true;
    }

    /**
     * @param  array{id: string, title: string, url: string, domain: string, published_at?: string|null, fetched_at?: string|null}  $source
     */
    public function addWebSource(array $source): void
    {
        $url = (string) ($source['url'] ?? '');
        if ($url === '') {
            return;
        }

        foreach ($this->webSources as $existing) {
            if ($existing['url'] === $url) {
                return;
            }
        }

        if (count($this->webSources) >= 12) {
            return;
        }

        $this->webSources[] = [
            'id' => (string) ($source['id'] ?? 'web-'.(count($this->webSources) + 1)),
            'title' => (string) ($source['title'] ?? ''),
            'url' => $url,
            'domain' => (string) ($source['domain'] ?? ''),
            'published_at' => isset($source['published_at']) && is_string($source['published_at']) ? $source['published_at'] : null,
            'fetched_at' => isset($source['fetched_at']) && is_string($source['fetched_at']) ? $source['fetched_at'] : null,
        ];
    }
}
