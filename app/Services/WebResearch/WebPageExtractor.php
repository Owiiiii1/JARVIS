<?php

namespace App\Services\WebResearch;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class WebPageExtractor
{
    /**
     * @return array{title: string, content: string, published_at: string|null, canonical_url: string|null}
     */
    public function extract(string $html, ?string $fallbackTitle = null): array
    {
        $html = $this->stripNoise($html);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $text = $this->normalizeWhitespace(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return [
                'title' => (string) $fallbackTitle,
                'content' => $text,
                'published_at' => null,
                'canonical_url' => null,
            ];
        }

        $xpath = new DOMXPath($dom);
        $this->removeNodes($xpath, '//script|//style|//noscript|//iframe|//svg|//canvas|//form|//nav|//footer|//header|//aside');

        $title = $this->firstText($xpath, '//title')
            ?: $this->meta($xpath, 'og:title')
            ?: (string) $fallbackTitle;

        $canonical = $this->canonical($xpath);
        $published = $this->publishedAt($xpath);

        $main = $this->firstNode($xpath, '//article|//main|//*[@role="main"]') ?? $dom->getElementsByTagName('body')->item(0);
        $chunks = [];

        if ($main instanceof DOMElement) {
            foreach ($xpath->query('.//h1|.//h2|.//h3|.//p|.//li|.//pre|.//code', $main) ?: [] as $node) {
                $text = $this->normalizeWhitespace($node->textContent ?? '');
                if ($text === '') {
                    continue;
                }
                $chunks[] = $text;
            }

            if ($chunks === []) {
                $chunks[] = $this->normalizeWhitespace($main->textContent ?? '');
            }
        }

        $content = $this->dedupeChunks($chunks);

        return [
            'title' => $this->normalizeWhitespace($title),
            'content' => $content,
            'published_at' => $published,
            'canonical_url' => $canonical,
        ];
    }

    public function extractPlain(string $text): string
    {
        return $this->normalizeWhitespace($text);
    }

    public function extractJson(string $json, int $maxChars): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return $this->normalizeWhitespace(mb_substr($json, 0, $maxChars));
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $json;

        return mb_substr($encoded, 0, $maxChars);
    }

    private function stripNoise(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;

        return $html;
    }

    private function removeNodes(DOMXPath $xpath, string $query): void
    {
        foreach ($xpath->query($query) ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function firstText(DOMXPath $xpath, string $query): string
    {
        $node = $this->firstNode($xpath, $query);

        return $this->normalizeWhitespace($node?->textContent ?? '');
    }

    private function firstNode(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node : null;
    }

    private function meta(DOMXPath $xpath, string $property): string
    {
        foreach (['property', 'name'] as $attr) {
            $nodes = $xpath->query('//meta[translate(@'.$attr.', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'.strtolower($property).'"]/@content');
            $value = $nodes !== false ? trim((string) $nodes->item(0)?->nodeValue) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function canonical(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]/@href');
        $href = $nodes !== false ? trim((string) $nodes->item(0)?->nodeValue) : '';

        return $href !== '' ? $href : null;
    }

    private function publishedAt(DOMXPath $xpath): ?string
    {
        foreach (['article:published_time', 'datePublished', 'pubdate', 'publishdate', 'date', 'dc.date'] as $name) {
            $value = $this->meta($xpath, $name);
            if ($value !== '') {
                return $value;
            }
        }

        $nodes = $xpath->query('//time/@datetime');
        $value = $nodes !== false ? trim((string) $nodes->item(0)?->nodeValue) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $chunks
     */
    private function dedupeChunks(array $chunks): string
    {
        $seen = [];
        $kept = [];

        foreach ($chunks as $chunk) {
            $key = mb_strtolower(mb_substr($chunk, 0, 180));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $chunk;
        }

        return $this->normalizeWhitespace(implode("\n\n", $kept));
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\f\v]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
