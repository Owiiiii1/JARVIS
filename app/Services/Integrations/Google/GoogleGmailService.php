<?php

namespace App\Services\Integrations\Google;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GoogleGmailService
{
    public function __construct(
        private readonly GoogleCredentialService $credentials,
        private readonly GmailMimeParser $parser,
        private readonly GmailMimeBuilder $builder,
        private readonly GmailAddressValidator $addresses,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{messages: list<array<string, mixed>>, truncated: bool, next_page_available: bool, result_count: int, unread_count: int}
     */
    public function searchMessages(IntegrationAccount $account, string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new IntegrationException('invalid_arguments', 'Search query is required.');
        }

        return $this->collectSummaries(
            $account,
            $query,
            $options,
            (int) config('google_gmail.max_search_results', 15),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{messages: list<array<string, mixed>>, truncated: bool, next_page_available: bool, result_count: int, unread_count: int}
     */
    public function listMessages(IntegrationAccount $account, array $options = []): array
    {
        return $this->collectSummaries(
            $account,
            $this->listQuery($options),
            $options,
            (int) config('google_gmail.max_messages', 15),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(IntegrationAccount $account, string $messageId): array
    {
        $payload = $this->get($account, '/users/me/messages/'.$this->encodeId($messageId), [
            'format' => 'full',
        ]);

        return $this->parser->parseMessage($payload);
    }

    /**
     * @return array{thread_id: string, messages: list<array<string, mixed>>, truncated: bool, result_count: int}
     */
    public function getThread(IntegrationAccount $account, string $threadId, ?int $maxMessages = null): array
    {
        $payload = $this->get($account, '/users/me/threads/'.$this->encodeId($threadId), [
            'format' => 'full',
        ]);

        $rawMessages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        usort($rawMessages, function (mixed $left, mixed $right): int {
            $leftDate = is_array($left) ? (int) ($left['internalDate'] ?? 0) : 0;
            $rightDate = is_array($right) ? (int) ($right['internalDate'] ?? 0) : 0;

            return $leftDate <=> $rightDate;
        });

        $limit = $this->bound($maxMessages, (int) config('google_gmail.max_thread_messages', 10));
        $maxChars = max(1, (int) config('google_gmail.max_total_thread_chars', 20000));
        $messages = [];
        $used = 0;
        $truncated = count($rawMessages) > $limit;

        foreach (array_slice($rawMessages, 0, $limit) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $parsed = $this->parser->parseMessage($raw);
            $body = (string) ($parsed['body'] ?? '');
            $remaining = $maxChars - $used;
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            if (mb_strlen($body) > $remaining) {
                $parsed['body'] = mb_substr($body, 0, $remaining);
                $parsed['truncated'] = true;
                $truncated = true;
            }

            $used += mb_strlen((string) $parsed['body']);
            $messages[] = $parsed;

            if ($used >= $maxChars) {
                $truncated = true;
                break;
            }
        }

        return [
            'thread_id' => (string) ($payload['id'] ?? $threadId),
            'messages' => $messages,
            'truncated' => $truncated,
            'result_count' => count($messages),
        ];
    }

    /**
     * @return array{labels: list<array<string, mixed>>, truncated: bool, result_count: int}
     */
    public function listLabels(IntegrationAccount $account): array
    {
        $payload = $this->get($account, '/users/me/labels');
        $raw = is_array($payload['labels'] ?? null) ? $payload['labels'] : [];
        $limit = (int) config('google_gmail.max_labels', 50);
        $truncated = count($raw) > $limit;
        $labels = [];

        foreach (array_slice($raw, 0, $limit) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $labels[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? 'user'),
                'messages_total' => isset($item['messagesTotal']) ? (int) $item['messagesTotal'] : null,
                'messages_unread' => isset($item['messagesUnread']) ? (int) $item['messagesUnread'] : null,
                'threads_total' => isset($item['threadsTotal']) ? (int) $item['threadsTotal'] : null,
                'threads_unread' => isset($item['threadsUnread']) ? (int) $item['threadsUnread'] : null,
            ];
        }

        return [
            'labels' => $labels,
            'truncated' => $truncated,
            'result_count' => count($labels),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function createDraft(IntegrationAccount $account, array $message): array
    {
        $prepared = $this->prepareOutbound($account, $message);
        $payload = $this->post($account, '/users/me/drafts', [
            'message' => $prepared,
        ], retrySafe: false);

        $remote = is_array($payload['message'] ?? null) ? $payload['message'] : [];

        return [
            'draft_id' => (string) ($payload['id'] ?? ''),
            'message_id' => (string) ($remote['id'] ?? ''),
            'thread_id' => (string) ($remote['threadId'] ?? ($prepared['threadId'] ?? '')),
            'result_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function sendMessage(IntegrationAccount $account, array $message): array
    {
        $prepared = $this->prepareOutbound($account, $message);

        try {
            $payload = $this->post($account, '/users/me/messages/send', $prepared, retrySafe: false, send: true);
        } catch (IntegrationException $exception) {
            if ($exception->error === 'gmail_invalid_recipient') {
                throw $exception;
            }

            throw new IntegrationException(
                $exception->error === 'gmail_unavailable' ? 'gmail_send_failed' : $exception->error,
                'Gmail send failed.',
                false,
            );
        }

        return [
            'message_id' => (string) ($payload['id'] ?? ''),
            'thread_id' => (string) ($payload['threadId'] ?? ($prepared['threadId'] ?? '')),
            'result_count' => 1,
        ];
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return array<string, mixed>
     */
    public function modifyLabels(
        IntegrationAccount $account,
        ?string $messageId,
        ?string $threadId,
        array $add,
        array $remove,
    ): array {
        $add = $this->addresses->labelIds($add);
        $remove = $this->addresses->labelIds($remove);

        if ($add === [] && $remove === []) {
            throw new IntegrationException('invalid_arguments', 'At least one label change is required.');
        }

        $body = [
            'addLabelIds' => $add,
            'removeLabelIds' => $remove,
        ];

        if (filled($messageId)) {
            $payload = $this->post(
                $account,
                '/users/me/messages/'.$this->encodeId((string) $messageId).'/modify',
                $body,
                retrySafe: false,
            );
        } elseif (filled($threadId)) {
            $payload = $this->post(
                $account,
                '/users/me/threads/'.$this->encodeId((string) $threadId).'/modify',
                $body,
                retrySafe: false,
            );
        } else {
            throw new IntegrationException('invalid_arguments', 'message_id or thread_id is required.');
        }

        $labels = is_array($payload['labelIds'] ?? null)
            ? array_values(array_map('strval', $payload['labelIds']))
            : $add;

        return [
            'id' => (string) ($payload['id'] ?? $messageId ?? $threadId ?? ''),
            'thread_id' => (string) ($payload['threadId'] ?? $threadId ?? ''),
            'labels' => $labels,
            'added' => $add,
            'removed' => $remove,
            'result_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{messages: list<array<string, mixed>>, truncated: bool, next_page_available: bool, result_count: int, unread_count: int}
     */
    private function collectSummaries(IntegrationAccount $account, string $query, array $options, int $defaultLimit): array
    {
        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, $defaultLimit);
        $includeSpamTrash = (bool) ($options['include_spam_trash'] ?? false);
        $labelIds = $this->addresses->labelIds($options['label_ids'] ?? []);
        $items = [];
        $pageToken = null;
        $truncated = false;
        $nextPage = false;

        do {
            $params = [
                'maxResults' => min(100, $limit - count($items) + 1),
                'includeSpamTrash' => $includeSpamTrash ? 'true' : 'false',
            ];
            if ($query !== '') {
                $params['q'] = $query;
            }
            if (is_string($pageToken) && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $payload = $this->get($account, '/users/me/messages', $params, ['labelIds' => $labelIds]);
            $page = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

            foreach ($page as $raw) {
                if (! is_array($raw) || ! filled($raw['id'] ?? null)) {
                    continue;
                }

                if (count($items) >= $limit) {
                    $truncated = true;
                    $nextPage = true;
                    break 2;
                }

                $items[] = $this->hydrateSummary($account, (string) $raw['id']);
            }

            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null);

        if ($pageToken !== null) {
            $truncated = true;
            $nextPage = true;
        }

        $unread = count(array_filter($items, static fn (array $row): bool => (bool) ($row['unread'] ?? false)));

        return [
            'messages' => $items,
            'truncated' => $truncated,
            'next_page_available' => $nextPage,
            'result_count' => count($items),
            'unread_count' => $unread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateSummary(IntegrationAccount $account, string $messageId): array
    {
        $payload = $this->get(
            $account,
            '/users/me/messages/'.$this->encodeId($messageId),
            ['format' => 'metadata'],
            ['metadataHeaders' => ['From', 'To', 'Cc', 'Subject', 'Date']],
        );

        return $this->parser->parseSummary($payload);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function listQuery(array $options): string
    {
        $parts = [];
        $explicit = trim((string) ($options['query'] ?? ''));
        if ($explicit !== '') {
            $parts[] = $explicit;
        }

        $mailbox = strtoupper(trim((string) ($options['mailbox'] ?? $options['filter'] ?? '')));
        if ($mailbox === '' && $explicit === '') {
            $parts[] = 'in:inbox';
        } elseif ($mailbox !== '' && ! in_array($mailbox, ['ALL', 'ANY'], true)) {
            $parts[] = 'in:'.strtolower($mailbox);
        }

        if (($options['unread'] ?? false) === true && ! str_contains(strtolower($explicit), 'is:unread')) {
            $parts[] = 'is:unread';
        }

        $newerThan = trim((string) ($options['newer_than'] ?? ''));
        if ($newerThan !== '') {
            $parts[] = 'newer_than:'.$newerThan;
        }

        foreach (['after', 'before'] as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key.':'.$value;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{raw: string, threadId?: string}
     */
    private function prepareOutbound(IntegrationAccount $account, array $message): array
    {
        $replyTo = trim((string) ($message['reply_to_message_id'] ?? $message['in_reply_to_message_id'] ?? ''));
        $threadId = trim((string) ($message['thread_id'] ?? ''));
        $inReplyTo = null;
        $references = null;
        $replyAll = (bool) ($message['reply_all'] ?? false);

        if ($replyTo !== '') {
            $original = $this->getMessage($account, $replyTo);
            $threadId = $threadId !== '' ? $threadId : (string) ($original['thread_id'] ?? '');
            $inReplyTo = $this->normalizeMessageIdHeader((string) ($original['message_id_header'] ?? ''));
            $references = trim((string) ($original['references'] ?? ''));
            if ($inReplyTo !== null) {
                $references = trim($references === '' ? $inReplyTo : $references.' '.$inReplyTo);
            }

            $message['to'] = $this->replyRecipients($original, $message, $replyAll);
            if (trim((string) ($message['subject'] ?? '')) === '') {
                $message['subject'] = $this->replySubject((string) ($original['subject'] ?? ''));
            }
        }

        $maxRecipients = (int) config('google_gmail.max_recipients', 20);
        $to = $this->addresses->emails($message['to'] ?? [], $maxRecipients, required: true, field: 'to');
        $cc = $this->addresses->emails($message['cc'] ?? [], (int) config('google_gmail.max_cc', 10), field: 'cc');
        $bcc = $this->addresses->emails($message['bcc'] ?? [], (int) config('google_gmail.max_cc', 10), field: 'bcc');

        if (count($to) + count($cc) + count($bcc) > $maxRecipients) {
            throw new IntegrationException('invalid_arguments', 'Too many recipients.');
        }

        $subject = $this->addresses->headerText(
            $message['subject'] ?? '',
            (int) config('google_gmail.max_subject_chars', 200),
            required: true,
        );
        $body = $this->addresses->bodyText(
            $message['body'] ?? '',
            (int) config('google_gmail.max_outbound_body_chars', 8000),
            required: true,
        );

        $raw = $this->builder->encode([
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'body' => $body,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
        ]);

        $payload = ['raw' => $raw];
        if ($threadId !== '') {
            $payload['threadId'] = $threadId;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function replyRecipients(array $original, array $message, bool $replyAll): array
    {
        $supplied = $this->addresses->emails($message['to'] ?? [], (int) config('google_gmail.max_recipients', 20));
        if ($supplied !== []) {
            return $supplied;
        }

        $to = [];
        $from = $this->extractEmail((string) ($original['from'] ?? ''));
        if ($from !== null) {
            $to[] = $from;
        }

        if ($replyAll) {
            foreach (array_merge((array) ($original['to'] ?? []), (array) ($original['cc'] ?? [])) as $raw) {
                $email = $this->extractEmail((string) $raw);
                if ($email !== null) {
                    $to[] = $email;
                }
            }
        }

        return array_values(array_unique($to));
    }

    private function replySubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return 'Re:';
        }

        return preg_match('/^re:\s*/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    private function extractEmail(string $raw): ?string
    {
        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            $raw = $matches[1];
        }

        $email = strtolower(trim($raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function normalizeMessageIdHeader(string $header): ?string
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if (! str_starts_with($header, '<')) {
            $header = '<'.$header;
        }
        if (! str_ends_with($header, '>')) {
            $header .= '>';
        }

        return $header;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, list<string>>  $repeated
     * @return array<string, mixed>
     */
    private function get(IntegrationAccount $account, string $path, array $query = [], array $repeated = []): array
    {
        return $this->send($account, 'GET', $path, query: $query, repeated: $repeated, retrySafe: true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(
        IntegrationAccount $account,
        string $path,
        array $body,
        bool $retrySafe = false,
        bool $send = false,
    ): array {
        return $this->send($account, 'POST', $path, $body, retrySafe: $retrySafe, send: $send);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, list<string>>  $repeated
     * @return array<string, mixed>
     */
    private function send(
        IntegrationAccount $account,
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $repeated = [],
        bool $retrySafe = false,
        bool $send = false,
    ): array {
        $token = $this->credentials->getValidAccessToken($account);
        $url = $this->url($path, $query, $repeated);
        $retries = $retrySafe ? max(0, (int) config('google_gmail.get_retries', 1)) : 0;

        $request = $this->http()
            ->withToken($token)
            ->retry($retries, 200, throw: false);

        try {
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->post($url, $body);
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->logFailure($method, $send ? 'gmail_send_failed' : 'gmail_unavailable');
            throw new IntegrationException(
                $send ? 'gmail_send_failed' : 'gmail_unavailable',
                'Gmail is unavailable.',
                ! $send && $retrySafe,
            );
        }

        if (! $response->successful()) {
            $this->failFromResponse($response, $path, $send);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, list<string>>  $repeated
     */
    private function url(string $path, array $query = [], array $repeated = []): string
    {
        $url = rtrim((string) config('google_gmail.api_base'), '/').$path;
        $parts = [];
        if ($query !== []) {
            $parts[] = http_build_query($query);
        }
        foreach ($repeated as $key => $values) {
            foreach ($values as $value) {
                if (trim((string) $value) === '') {
                    continue;
                }
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
        }

        return $parts === [] ? $url : $url.'?'.implode('&', $parts);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('google_gmail.timeout', 10))
            ->connectTimeout((int) config('google_gmail.connect_timeout', 5))
            ->acceptJson()
            ->asJson();
    }

    private function failFromResponse(Response $response, string $path, bool $send): never
    {
        $status = $response->status();
        $code = $this->normalizeError($status, $response, $path, $send);
        $this->logFailure('http', $code, $status);

        throw new IntegrationException(
            $code,
            'Gmail request failed.',
            in_array($code, ['gmail_rate_limited', 'gmail_unavailable'], true),
        );
    }

    private function normalizeError(int $status, Response $response, string $path, bool $send): string
    {
        $reason = strtolower((string) ($response->json('error.status') ?? $response->json('error.errors.0.reason') ?? $response->json('error.message') ?? ''));

        if ($status === 401 || str_contains($reason, 'unauth') || str_contains($reason, 'invalid_grant')) {
            return 'google_not_connected';
        }

        if ($status === 403 && (str_contains($reason, 'scope') || str_contains($reason, 'insufficient'))) {
            return 'gmail_scope_required';
        }

        if ($status === 403) {
            return 'gmail_forbidden';
        }

        if ($status === 404) {
            return str_contains($path, '/threads/') ? 'gmail_thread_not_found' : 'gmail_message_not_found';
        }

        if ($status === 409) {
            return 'gmail_conflict';
        }

        if ($status === 429) {
            return 'gmail_rate_limited';
        }

        if ($status === 400 && (str_contains($reason, 'recipient') || str_contains($reason, 'invalidto') || str_contains($reason, 'invalidtoheader'))) {
            return 'gmail_invalid_recipient';
        }

        if ($send) {
            return 'gmail_send_failed';
        }

        if ($status >= 500) {
            return 'gmail_unavailable';
        }

        return 'gmail_unavailable';
    }

    private function encodeId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $id) !== 1) {
            throw new IntegrationException('invalid_arguments', 'Gmail resource id is invalid.');
        }

        return rawurlencode($id);
    }

    private function bound(?int $requested, int $configured): int
    {
        $limit = $configured > 0 ? $configured : 1;

        if ($requested === null || $requested < 1) {
            return $limit;
        }

        return min($requested, $limit);
    }

    private function logFailure(string $action, string $code, ?int $status = null): void
    {
        Log::info('google gmail', [
            'provider' => 'google',
            'action' => $action,
            'success' => false,
            'error_code' => $code,
            'http_status' => $status,
        ]);
    }
}
