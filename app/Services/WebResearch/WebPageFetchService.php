<?php

namespace App\Services\WebResearch;

use App\Services\WebResearch\DTO\WebPageDocument;
use App\Services\WebResearch\Exceptions\WebResearchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WebPageFetchService
{
    public function __construct(
        private readonly WebUrlGuard $urls,
        private readonly WebPageExtractor $extractor,
    ) {}

    public function fetch(string $url, int $maxChars): WebPageDocument
    {
        $requested = $this->urls->assertSafeUrl($url);
        $current = $requested;
        $maxRedirects = max(0, (int) config('web_research.max_redirects', 3));
        $retries = max(0, (int) config('web_research.fetch_retries', 1));
        $response = null;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $current = $this->urls->assertSafeUrl($current);
            $attempt = 0;

            while (true) {
                try {
                    $response = $this->request($current);
                    break;
                } catch (ConnectionException) {
                    if ($attempt >= $retries) {
                        throw new WebResearchException('web_fetch_timeout', 'Web fetch timed out.', retryable: true);
                    }
                    $attempt++;
                } catch (WebResearchException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new WebResearchException('web_fetch_failed', 'Web fetch failed.');
                }
            }

            if ($response === null) {
                throw new WebResearchException('web_fetch_failed', 'Web fetch failed.');
            }

            if ($response->redirect()) {
                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    throw new WebResearchException('web_fetch_failed', 'Redirect location missing.');
                }
                $current = $this->resolveRedirect($current, $location);

                continue;
            }

            return $this->documentFromResponse($requested, $current, $response, $maxChars);
        }

        throw new WebResearchException('web_fetch_failed', 'Too many redirects.');
    }

    private function request(string $url): Response
    {
        $maxBytes = max(32_000, (int) config('web_research.max_response_bytes', 1_500_000));

        $response = Http::withHeaders([
            'User-Agent' => (string) config('web_research.user_agent', 'JarvisWebResearch/1.0'),
            'Accept' => 'text/html,application/xhtml+xml,text/plain,application/json;q=0.9,*/*;q=0.1',
        ])
            ->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
            ])
            ->timeout((int) config('web_research.timeout', 12))
            ->connectTimeout((int) config('web_research.connect_timeout', 5))
            ->get($url);

        $length = $response->header('Content-Length');
        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw new WebResearchException('web_fetch_too_large', 'Response is too large.');
        }

        $body = (string) $response->body();
        if (strlen($body) > $maxBytes) {
            throw new WebResearchException('web_fetch_too_large', 'Response is too large.');
        }

        return $response;
    }

    private function resolveRedirect(string $current, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $this->urls->assertSafeUrl($location);
        }

        $parts = parse_url($current);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $this->urls->assertSafeUrl($scheme.':'.$location);
        }

        if (str_starts_with($location, '/')) {
            return $this->urls->assertSafeUrl($scheme.'://'.$host.$port.$location);
        }

        $basePath = $parts['path'] ?? '/';
        $dir = str_contains($basePath, '/') ? substr($basePath, 0, (int) strrpos($basePath, '/') + 1) : '/';

        return $this->urls->assertSafeUrl($scheme.'://'.$host.$port.$dir.$location);
    }

    private function documentFromResponse(string $requested, string $finalUrl, Response $response, int $maxChars): WebPageDocument
    {
        $status = $response->status();

        if ($status === 404 || $status === 410) {
            throw new WebResearchException('web_fetch_not_found', 'Page was not found.');
        }

        if ($status === 401 || $status === 403) {
            throw new WebResearchException('web_fetch_forbidden', 'Page is forbidden.');
        }

        if ($status === 408 || $status === 504) {
            throw new WebResearchException('web_fetch_timeout', 'Web fetch timed out.', retryable: true);
        }

        if ($status < 200 || $status >= 300) {
            throw new WebResearchException('web_fetch_failed', 'Web fetch failed.');
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $mime = trim(explode(';', $contentType)[0]);
        $body = $this->decodeBody((string) $response->body(), $contentType);

        if ($this->looksBinary($body, $mime)) {
            throw new WebResearchException('web_fetch_unsupported_content', 'Binary content is not supported.');
        }

        $kind = $this->kind($mime, $body);

        if ($kind === null) {
            throw new WebResearchException('web_fetch_unsupported_content', 'Content type is not supported.');
        }

        $truncated = false;
        $published = null;
        $title = '';
        $content = '';

        if ($kind === 'html') {
            $extracted = $this->extractor->extract($body);
            $title = $extracted['title'];
            $content = $extracted['content'];
            $published = $extracted['published_at'];
            $canonical = $extracted['canonical_url'];
            if (is_string($canonical) && $canonical !== '') {
                try {
                    $finalUrl = $this->urls->assertSafeUrl($canonical);
                } catch (WebResearchException) {
                }
            }
        } elseif ($kind === 'json') {
            $title = $this->urls->domainOf($finalUrl);
            $content = $this->extractor->extractJson($body, $maxChars);
        } else {
            $title = $this->urls->domainOf($finalUrl);
            $content = $this->extractor->extractPlain($body);
        }

        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, 0, $maxChars);
            $truncated = true;
        }

        return new WebPageDocument(
            requestedUrl: $requested,
            finalUrl: $finalUrl,
            title: $title,
            domain: $this->urls->domainOf($finalUrl),
            content: $content,
            charCount: mb_strlen($content),
            truncated: $truncated,
            fetchedAt: now()->toIso8601String(),
            publishedAt: $published,
            contentType: $mime !== '' ? $mime : $kind,
        );
    }

    private function decodeBody(string $body, string $contentType): string
    {
        if (preg_match('/charset=([\\w-]+)/i', $contentType, $matches) === 1) {
            $charset = strtoupper($matches[1]);
            if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }

        return $body;
    }

    private function looksBinary(string $body, string $mime): bool
    {
        if (str_contains($mime, 'octet-stream') || str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/pdf') {
            return true;
        }

        return str_contains($body, "\0");
    }

    private function kind(string $mime, string $body): ?string
    {
        if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || str_contains($mime, 'html')) {
            return 'html';
        }

        if ($mime === 'text/plain') {
            return 'text';
        }

        if ($mime === 'application/json' || str_ends_with($mime, '+json')) {
            return 'json';
        }

        if ($mime === '' || $mime === 'application/octet-stream') {
            $trim = ltrim($body);
            if (str_starts_with($trim, '<') && (str_contains(mb_strtolower(mb_substr($trim, 0, 200)), 'html') || str_starts_with(mb_strtolower($trim), '<!doctype'))) {
                return 'html';
            }
            if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
                return 'json';
            }
            if (! $this->looksBinary($body, $mime)) {
                return 'text';
            }
        }

        return null;
    }
}
