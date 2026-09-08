<?php

namespace App\Services\Watchers;

use App\Enums\WatcherTriggerType;
use App\Models\Watcher;

final class GmailWatcherQuery
{
    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function normalize(array $source): array
    {
        unset($source['user_id'], $source['integration_account_id'], $source['token'], $source['body'], $source['raw']);

        $senders = self::unique(array_merge(
            self::stringList($source['senders'] ?? null),
            self::stringList($source['sender'] ?? null),
        ));
        $domains = self::unique(array_merge(
            self::stringList($source['sender_domains'] ?? null),
            self::stringList($source['sender_domain'] ?? null),
            self::stringList($source['domains'] ?? null),
        ));

        $normalizedSenders = [];
        $normalizedDomains = $domains;
        foreach ($senders as $sender) {
            if (self::isEmail($sender)) {
                $normalizedSenders[] = mb_strtolower($sender);

                continue;
            }
            if (self::isDomain($sender)) {
                $normalizedDomains[] = self::domain($sender);
            }
        }

        $normalizedSenders = self::unique($normalizedSenders);
        $normalizedDomains = self::unique(array_map(
            static fn (string $domain): string => self::domain($domain),
            $normalizedDomains,
        ));

        $subject = trim((string) ($source['subject'] ?? ''));
        $threadId = trim((string) ($source['thread_id'] ?? ''));
        $query = trim((string) ($source['query'] ?? ''));

        $out = [];
        if ($normalizedSenders !== []) {
            $out['senders'] = $normalizedSenders;
            if (count($normalizedSenders) === 1) {
                $out['sender'] = $normalizedSenders[0];
            }
        }
        if ($normalizedDomains !== []) {
            $out['sender_domains'] = $normalizedDomains;
        }
        if ($subject !== '') {
            $out['subject'] = WatcherSupport::summary($subject, 180);
        }
        if ($threadId !== '') {
            $out['thread_id'] = mb_substr($threadId, 0, 128);
        }
        if ($query !== '' && ! self::hasStructuredFilter($out)) {
            $out['query'] = WatcherSupport::summary($query, 240);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function merge(array $existing, array $incoming): array
    {
        $left = self::normalize($existing);
        $right = self::normalize($incoming);

        return self::normalize([
            'senders' => array_merge(self::stringList($left['senders'] ?? null), self::stringList($right['senders'] ?? null)),
            'sender_domains' => array_merge(self::stringList($left['sender_domains'] ?? null), self::stringList($right['sender_domains'] ?? null)),
            'subject' => $right['subject'] ?? ($left['subject'] ?? ''),
            'thread_id' => $right['thread_id'] ?? ($left['thread_id'] ?? ''),
            'query' => $right['query'] ?? ($left['query'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function compile(array $source): string
    {
        $source = self::normalize($source);
        $parts = [];

        if (isset($source['thread_id']) && trim((string) $source['thread_id']) !== '') {
            $parts[] = 'thread:'.trim((string) $source['thread_id']);
        }

        $from = [];
        foreach (self::stringList($source['senders'] ?? null) as $sender) {
            $from[] = 'from:'.$sender;
        }
        foreach (self::stringList($source['sender_domains'] ?? null) as $domain) {
            $from[] = 'from:'.$domain;
        }
        if ($from !== []) {
            $parts[] = count($from) === 1 ? $from[0] : '('.implode(' OR ', $from).')';
        }

        if (isset($source['subject']) && trim((string) $source['subject']) !== '') {
            $parts[] = 'subject:'.trim((string) $source['subject']);
        }

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        $query = trim((string) ($source['query'] ?? ''));

        return $query !== '' ? $query : '';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function hasFilter(array $source): bool
    {
        $source = self::normalize($source);

        return self::hasStructuredFilter($source)
            || (isset($source['query']) && trim((string) $source['query']) !== '');
    }

    public static function isEventWatcher(Watcher $watcher): bool
    {
        if ($watcher->trigger_type !== WatcherTriggerType::GmailMessage) {
            return false;
        }

        return ! WatcherSchedule::isDigest($watcher);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    public static function humanLabels(array $source): array
    {
        $source = self::normalize($source);
        $labels = array_merge(
            self::stringList($source['senders'] ?? null),
            self::stringList($source['sender_domains'] ?? null),
        );

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function humanList(array $source): string
    {
        $labels = self::humanLabels($source);
        if ($labels === []) {
            $query = trim((string) (self::normalize($source)['query'] ?? ''));

            return $query !== '' && $query !== 'in:inbox' ? $query : 'выбранных отправителей';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' и '.$last;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function displayName(array $source): string
    {
        return WatcherSupport::displayName('Письма от '.self::humanList($source));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function messageMatches(array $source, string $sender, string $subject = ''): bool
    {
        $source = self::normalize($source);
        if (! self::hasFilter($source)) {
            return true;
        }

        $haystack = mb_strtolower($sender);
        $subjectHay = mb_strtolower($subject);
        $ok = true;

        $senders = self::stringList($source['senders'] ?? null);
        $domains = self::stringList($source['sender_domains'] ?? null);
        if ($senders !== [] || $domains !== []) {
            $ok = false;
            foreach ($senders as $needle) {
                if (str_contains($haystack, $needle)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                foreach ($domains as $domain) {
                    if (str_contains($haystack, $domain)) {
                        $ok = true;
                        break;
                    }
                }
            }
        }

        $needSubject = trim((string) ($source['subject'] ?? ''));
        if ($ok && $needSubject !== '') {
            $ok = str_contains($subjectHay, mb_strtolower($needSubject));
        }

        return $ok;
    }

    /**
     * @return array{senders: list<string>, sender_domains: list<string>}
     */
    public static function extractFromText(string $text): array
    {
        $senders = [];
        $domains = [];

        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $emails);
        foreach ($emails[0] ?? [] as $email) {
            $senders[] = mb_strtolower(trim((string) $email));
        }

        preg_match_all('/(?:^|[^a-z0-9._%+-])@([a-z0-9-]+(?:\.[a-z0-9-]+)+)/i', $text, $atDomains);
        foreach ($atDomains[1] ?? [] as $domain) {
            $domains[] = self::domain((string) $domain);
        }

        preg_match_all('/\b([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})\b/i', $text, $bare);
        $emailHosts = [];
        foreach ($senders as $email) {
            $parts = explode('@', $email);
            if (isset($parts[1])) {
                $emailHosts[] = $parts[1];
            }
        }
        foreach ($bare[1] ?? [] as $domain) {
            $candidate = self::domain((string) $domain);
            if (! self::isDomain($candidate) || self::isEmail($candidate) || in_array($candidate, $emailHosts, true)) {
                continue;
            }
            $domains[] = $candidate;
        }

        return [
            'senders' => self::unique($senders),
            'sender_domains' => self::unique($domains),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function hasStructuredFilter(array $source): bool
    {
        return self::stringList($source['senders'] ?? null) !== []
            || self::stringList($source['sender_domains'] ?? null) !== []
            || trim((string) ($source['subject'] ?? '')) !== ''
            || trim((string) ($source['thread_id'] ?? '')) !== '';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = preg_split('/[,;]+/', $value) ?: [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function unique(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $value = mb_strtolower(trim($item));
            if ($value === '' || isset($out[$value])) {
                continue;
            }
            $out[$value] = $value;
        }

        return array_values($out);
    }

    private static function domain(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = ltrim($value, '@');
        $value = preg_replace('/^from:/i', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B<>");
    }

    private static function isEmail(string $value): bool
    {
        return filter_var(mb_strtolower(trim($value)), FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function isDomain(string $value): bool
    {
        $value = self::domain($value);

        return preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i', $value) === 1
            && ! str_contains($value, '@');
    }
}
