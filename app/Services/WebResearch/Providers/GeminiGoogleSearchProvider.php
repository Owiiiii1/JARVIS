<?php

namespace App\Services\WebResearch\Providers;

use App\Enums\AiRoleKey;
use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\DTO\WebSearchHit;
use App\Services\WebResearch\DTO\WebSearchQuery;
use App\Services\WebResearch\DTO\WebSearchResultSet;
use App\Services\WebResearch\Exceptions\WebResearchException;
use App\Services\WebResearch\WebResearchSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiGoogleSearchProvider implements WebSearchProvider
{
    public function __construct(
        private readonly WebResearchSettingsService $settings,
    ) {}

    public function name(): string
    {
        return 'gemini_google';
    }

    public function isConfigured(): bool
    {
        $credential = $this->geminiCredential();

        return $credential !== null && $credential->is_connected && filled($credential->api_key);
    }

    public function search(WebSearchQuery $query): WebSearchResultSet
    {
        $credential = $this->geminiCredential();

        if ($credential === null || ! $credential->is_connected || ! filled($credential->api_key)) {
            throw new WebResearchException('web_search_not_configured', 'Web search is not configured.');
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $this->prompt($query)]],
            ]],
            'tools' => [
                ['google_search' => (object) []],
            ],
        ];

        try {
            $response = $this->post((string) $credential->api_key, $this->model($credential), $payload);

            if ($response->status() === 400) {
                $payload['tools'] = [
                    ['googleSearch' => (object) []],
                ];
                $response = $this->post((string) $credential->api_key, $this->model($credential), $payload);
            }
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

        $hits = $this->hitsFromGrounding($response->json() ?? [], $query);

        return new WebSearchResultSet(
            query: $query->query,
            results: $hits,
            provider: $this->name(),
            truncated: count($hits) >= $query->maxResults,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $apiKey, string $model, array $payload): Response
    {
        $model = ltrim($model, '/');
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        $effective = $this->settings->effective();

        return Http::timeout($effective->timeoutSeconds)
            ->connectTimeout($effective->connectTimeoutSeconds)
            ->acceptJson()
            ->withQueryParameters(['key' => $apiKey])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',
                $payload
            );
    }

    /**
     * Normalize Google grounding metadata into provider-neutral hits / WebSourceReference.
     *
     * @param  array<string, mixed>  $body
     * @return list<WebSearchHit>
     */
    private function hitsFromGrounding(array $body, WebSearchQuery $query): array
    {
        $candidate = $body['candidates'][0] ?? [];
        if (! is_array($candidate)) {
            return [];
        }

        $metadata = $candidate['groundingMetadata'] ?? $candidate['grounding_metadata'] ?? [];
        $chunks = is_array($metadata) ? ($metadata['groundingChunks'] ?? $metadata['grounding_chunks'] ?? []) : [];
        $supports = is_array($metadata) ? ($metadata['groundingSupports'] ?? $metadata['grounding_supports'] ?? []) : [];

        if (! is_array($chunks)) {
            $chunks = [];
        }

        $snippets = $this->snippetsByChunk(is_array($supports) ? $supports : []);
        $snippetMax = $this->settings->effective()->maxSnippetChars;
        $hits = [];
        $rank = 1;

        foreach ($chunks as $index => $chunk) {
            $hit = $this->hitFromChunk($chunk, $index, $snippets, $snippetMax, $query, $rank);

            if ($hit === null) {
                continue;
            }

            $hits[] = $hit;
            $rank++;

            if (count($hits) >= $query->maxResults) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, string>  $snippets
     */
    private function hitFromChunk(mixed $chunk, int|string $index, array $snippets, int $snippetMax, WebSearchQuery $query, int $rank): ?WebSearchHit
    {
        if (! is_array($chunk)) {
            return null;
        }

        $web = $chunk['web'] ?? [];
        if (! is_array($web)) {
            return null;
        }

        $url = $this->normalizeSourceUrl(trim((string) ($web['uri'] ?? $web['url'] ?? '')));
        $title = trim((string) ($web['title'] ?? ''));

        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        if ($query->domains !== [] && ! $this->hostAllowed($url, $query->domains)) {
            return null;
        }

        if ($query->excludeDomains !== [] && $this->hostAllowed($url, $query->excludeDomains)) {
            return null;
        }

        $snippet = trim((string) ($snippets[(int) $index] ?? ''));
        $truncated = mb_strlen($snippet) > $snippetMax;
        if ($truncated) {
            $snippet = mb_substr($snippet, 0, $snippetMax);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $domain = is_string($host) ? mb_strtolower($host) : '';
        $published = $web['publishedDate'] ?? $web['published_date'] ?? $chunk['publishedAt'] ?? null;

        return new WebSearchHit(
            id: 'web-'.$rank,
            title: $title !== '' ? $title : $domain,
            url: $url,
            domain: $domain,
            snippet: $snippet,
            publishedAt: is_string($published) && $published !== '' ? $published : null,
            rank: $rank,
            sourceType: 'web',
            truncated: $truncated,
        );
    }

    /**
     * @param  list<mixed>  $supports
     * @return array<int, string>
     */
    private function snippetsByChunk(array $supports): array
    {
        $snippets = [];

        foreach ($supports as $support) {
            if (! is_array($support)) {
                continue;
            }

            $text = trim((string) ($support['segment']['text'] ?? ''));
            $indices = $support['groundingChunkIndices'] ?? $support['grounding_chunk_indices'] ?? [];

            if ($text === '' || ! is_array($indices)) {
                continue;
            }

            foreach ($indices as $index) {
                if (! is_numeric($index)) {
                    continue;
                }

                $key = (int) $index;
                $snippets[$key] = trim(($snippets[$key] ?? '').' '.$text);
            }
        }

        return $snippets;
    }

    /**
     * Unwrap Google grounding redirect URLs when the original target is in the query string.
     * Does not follow HTTP redirects.
     */
    private function normalizeSourceUrl(string $url): string
    {
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return $url;
        }

        $host = mb_strtolower($host);
        $redirectHosts = [
            'vertexaisearch.cloud.google.com',
            'grounding-api-redirect.google.com',
            'www.google.com',
            'google.com',
        ];

        $isRedirect = in_array($host, $redirectHosts, true)
            || str_ends_with($host, '.googleusercontent.com');

        if (! $isRedirect) {
            return $url;
        }

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (['url', 'q', 'destination', 'target', 'u'] as $key) {
            $candidate = trim((string) ($query[$key] ?? ''));
            if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                return $candidate;
            }
        }

        return $url;
    }

    /**
     * @param  list<string>  $domains
     */
    private function hostAllowed(string $url, array $domains): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = mb_strtolower($host);

        foreach ($domains as $domain) {
            $domain = mb_strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function prompt(WebSearchQuery $query): string
    {
        $lines = [
            'Search the public web for current sources matching this query.',
            'Return only facts grounded in the search results. Do not invent URLs.',
            'Query: '.$query->query,
        ];

        if ($query->recencyDays !== null && $query->recencyDays > 0) {
            $lines[] = 'Prefer sources from the last '.$query->recencyDays.' days.';
        }

        if ($query->domains !== []) {
            $lines[] = 'Prefer these domains: '.implode(', ', $query->domains).'.';
        }

        if ($query->excludeDomains !== []) {
            $lines[] = 'Exclude these domains: '.implode(', ', $query->excludeDomains).'.';
        }

        return implode("\n", $lines);
    }

    private function geminiCredential(): ?AiProviderSetting
    {
        return AiProviderSetting::query()->where('provider', 'gemini')->first();
    }

    private function model(AiProviderSetting $credential): string
    {
        $configured = trim((string) config('web_research.gemini_google.model'));

        if ($configured !== '') {
            return $configured;
        }

        if (filled($credential->active_model)) {
            return (string) $credential->active_model;
        }

        $role = AiRoleSetting::query()->where('role_key', AiRoleKey::OwnerConversation->value)->first();

        if ($role !== null && $role->provider === 'gemini' && filled($role->model)) {
            return (string) $role->model;
        }

        return 'gemini-2.5-flash';
    }
}
