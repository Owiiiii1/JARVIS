<?php

namespace App\Services\WebResearch\Providers;

use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchHit;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;
use App\Services\WebResearch\Exceptions\WebResearchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TavilyWebSearchProvider implements WebSearchProvider
{
    public function name(): string
    {
        return 'tavily';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('web_research.tavily.api_key')) !== '';
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        if (! $this->isConfigured()) {
            throw new WebResearchException('web_search_not_configured', 'Web search is not configured.');
        }

        $key = trim((string) config('web_research.tavily.api_key'));
        $base = rtrim((string) config('web_research.tavily.base_url'), '/');
        $snippetMax = max(80, (int) config('web_research.max_snippet_chars', 280));

        $payload = [
            'api_key' => $key,
            'query' => $query->query,
            'max_results' => $query->maxResults,
            'search_depth' => 'basic',
            'include_answer' => false,
            'include_images' => false,
            'include_raw_content' => false,
        ];

        if ($query->recencyDays !== null && $query->recencyDays > 0) {
            $payload['days'] = $query->recencyDays;
        }

        if ($query->domains !== []) {
            $payload['include_domains'] = $query->domains;
        }

        if ($query->excludeDomains !== []) {
            $payload['exclude_domains'] = $query->excludeDomains;
        }

        try {
            $response = Http::timeout((int) config('web_research.timeout', 12))
                ->connectTimeout((int) config('web_research.connect_timeout', 5))
                ->acceptJson()
                ->asJson()
                ->post($base.'/search', $payload);
        } catch (ConnectionException) {
            throw new WebResearchException('web_search_failed', 'Web search timed out.', retryable: true);
        } catch (Throwable) {
            throw new WebResearchException('web_search_failed', 'Web search failed.');
        }

        if ($response->status() === 429) {
            throw new WebResearchException('web_search_rate_limited', 'Web search rate limited.', retryable: true);
        }

        if ($response->failed()) {
            throw new WebResearchException('web_search_failed', 'Web search failed.');
        }

        $rows = $response->json('results');
        if (! is_array($rows)) {
            $rows = [];
        }

        $hits = [];
        $rank = 1;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = trim((string) ($row['url'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $snippet = trim((string) ($row['content'] ?? $row['snippet'] ?? ''));

            if ($url === '') {
                continue;
            }

            $truncated = mb_strlen($snippet) > $snippetMax;
            if ($truncated) {
                $snippet = mb_substr($snippet, 0, $snippetMax);
            }

            $host = parse_url($url, PHP_URL_HOST);
            $domain = is_string($host) ? mb_strtolower($host) : '';

            $published = $row['published_date'] ?? $row['published_at'] ?? null;
            $score = isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : null;

            $hits[] = new WebSearchHit(
                id: 'web-'.$rank,
                title: $title !== '' ? $title : $domain,
                url: $url,
                domain: $domain,
                snippet: $snippet,
                publishedAt: is_string($published) && $published !== '' ? $published : null,
                score: $score,
                rank: $rank,
                sourceType: 'web',
                truncated: $truncated,
            );
            $rank++;
        }

        return new WebSearchResultSet(
            query: $query->query,
            results: $hits,
            provider: $this->name(),
            truncated: count($rows) > count($hits),
        );
    }
}
