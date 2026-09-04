<?php

namespace App\Services\Integrations\Google;

final class GmailMimeParser
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function parseMessage(array $resource): array
    {
        $payload = is_array($resource['payload'] ?? null) ? $resource['payload'] : $resource;
        $headers = $this->headers(is_array($payload['headers'] ?? null) ? $payload['headers'] : []);
        $plain = null;
        $html = null;
        $attachments = [];
        $this->walk($payload, $plain, $html, $attachments);

        $fromHtml = false;
        $body = $plain;
        if (($body === null || trim($body) === '') && is_string($html) && $html !== '') {
            $body = $this->htmlToText($html);
            $fromHtml = true;
        }

        $body = (string) $body;
        $max = max(1, (int) config('google_gmail.max_body_chars', 12000));
        $truncated = mb_strlen($body) > $max;
        if ($truncated) {
            $body = mb_substr($body, 0, $max);
        }

        $snippetMax = max(40, (int) config('google_gmail.max_snippet_chars', 240));
        $snippet = trim((string) ($resource['snippet'] ?? ''));
        if ($snippet !== '' && mb_strlen($snippet) > $snippetMax) {
            $snippet = mb_substr($snippet, 0, $snippetMax);
        }

        $labelIds = is_array($resource['labelIds'] ?? null) ? array_values(array_map('strval', $resource['labelIds'])) : [];

        return [
            'id' => (string) ($resource['id'] ?? ''),
            'thread_id' => (string) ($resource['threadId'] ?? ''),
            'subject' => $headers['subject'] ?? '',
            'from' => $headers['from'] ?? '',
            'to' => $this->splitAddresses($headers['to'] ?? ''),
            'cc' => $this->splitAddresses($headers['cc'] ?? ''),
            'date' => $headers['date'] ?? '',
            'message_id_header' => $headers['message-id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references' => $headers['references'] ?? null,
            'body' => $body,
            'body_from_html' => $fromHtml,
            'snippet' => $snippet,
            'labels' => $labelIds,
            'unread' => in_array('UNREAD', $labelIds, true),
            'attachments' => $attachments,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function parseSummary(array $resource): array
    {
        $full = $this->parseMessage($resource);

        return [
            'id' => $full['id'],
            'thread_id' => $full['thread_id'],
            'subject' => $full['subject'],
            'from' => $full['from'],
            'to' => $full['to'],
            'date' => $full['date'],
            'snippet' => $full['snippet'],
            'labels' => $full['labels'],
            'unread' => $full['unread'],
        ];
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $value = trim((string) ($header['value'] ?? ''));
            if ($name === '') {
                continue;
            }

            $map[$name] = $value;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  list<array{filename: string, mime_type: string, size: int|null, attachment_id: string|null}>  $attachments
     */
    private function walk(array $part, ?string &$plain, ?string &$html, array &$attachments): void
    {
        $filename = trim((string) ($part['filename'] ?? ''));
        $mime = strtolower((string) ($part['mimeType'] ?? ''));
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        $data = (string) ($body['data'] ?? '');
        $attachmentId = isset($body['attachmentId']) ? (string) $body['attachmentId'] : null;
        $size = isset($body['size']) && is_numeric($body['size']) ? (int) $body['size'] : null;

        if ($filename !== '' || $attachmentId !== null) {
            $attachments[] = [
                'filename' => $filename !== '' ? $filename : 'attachment',
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                'size' => $size,
                'attachment_id' => $attachmentId,
            ];
        } elseif ($data !== '') {
            $decoded = $this->decodeBody($data);
            if (str_starts_with($mime, 'text/plain') && $plain === null) {
                $plain = $decoded;
            } elseif (str_starts_with($mime, 'text/html') && $html === null) {
                $html = $decoded;
            } elseif ($mime === '' && $plain === null) {
                $plain = $decoded;
            }
        }

        $children = is_array($part['parts'] ?? null) ? $part['parts'] : [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->walk($child, $plain, $html, $attachments);
            }
        }
    }

    public function decodeBody(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", "\xA0"], ' ', $text);

        return trim((string) preg_replace("/[ \t]+/", ' ', (string) preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    /**
     * @return list<string>
     */
    private function splitAddresses(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
