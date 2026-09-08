<?php

namespace App\Services\Watchers;

use App\Services\Watchers\DTO\WatcherObservation;

final class WatcherDigestFormatter
{
    /**
     * @param  list<array{observation: WatcherObservation, match?: mixed}>|list<WatcherObservation>  $matched
     */
    public function format(array $matched, bool $morning): string
    {
        $items = $this->items($matched);

        if ($items === []) {
            return $morning ? 'С утра новых писем нет.' : 'Новых писем нет.';
        }

        $important = [];
        $noise = 0;

        foreach ($items as $item) {
            if ($this->isNoise($item['sender'], $item['subject'])) {
                $noise++;

                continue;
            }

            $important[] = $item;
        }

        $total = count($items);
        $lead = $morning
            ? 'За ночь пришло '.$this->countPhrase($total).'.'
            : 'С последней проверки пришло '.$this->countPhrase($total).'.';

        $lines = [$lead, ''];
        $limit = max(3, (int) config('watchers.defaults.digest_max_items', 6));

        foreach (array_slice($important, 0, $limit) as $item) {
            $lines[] = '• '.$item['sender'].' — '.$item['subject'];
        }

        $omitted = max(0, count($important) - $limit);
        if ($omitted > 0) {
            $lines[] = '• Ещё '.$this->countPhrase($omitted).' по делу.';
        }

        if ($noise === 1) {
            $lines[] = '• Одно рекламное или техническое письмо, ничего важного.';
        } elseif ($noise > 1) {
            $lines[] = '• '.$noise.' рекламных или технических писем, ничего важного.';
        }

        return WatcherSupport::clip(trim(implode("\n", $lines)), $this->maxChars());
    }

    /**
     * @param  list<mixed>  $matched
     * @return list<array{sender: string, subject: string}>
     */
    private function items(array $matched): array
    {
        $items = [];

        foreach ($matched as $row) {
            $observation = $row instanceof WatcherObservation
                ? $row
                : ($row['observation'] ?? null);

            if (! $observation instanceof WatcherObservation) {
                continue;
            }

            $meta = $observation->metadata;
            $sender = $this->senderLabel((string) ($meta['sender'] ?? ''));
            $subject = trim((string) ($meta['subject'] ?? $observation->title));
            if ($subject === '') {
                $subject = 'без темы';
            }

            $items[] = [
                'sender' => $sender !== '' ? $sender : 'Неизвестный отправитель',
                'subject' => rtrim($subject, '.'),
            ];
        }

        return $items;
    }

    private function isNoise(string $sender, string $subject): bool
    {
        $haystack = mb_strtolower($sender.' '.$subject);

        return preg_match('/noreply|no-reply|no_reply|mailer-daemon|notifications@|newsletter|unsubscribe|github|gitlab|jira|slack|notion|linkedin|facebook|twitter|ads?[-_ ]?manager|promo|рассылк|уведомлен/u', $haystack) === 1;
    }

    private function senderLabel(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^"?([^"<]+)"?\s*</u', $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/^([^@]+)@/u', $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        return $raw;
    }

    private function countPhrase(int $count): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $count.' новых писем';
        }

        if ($mod10 === 1) {
            return $count.' новое письмо';
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $count.' новых письма';
        }

        return $count.' новых писем';
    }

    private function maxChars(): int
    {
        return max(200, (int) config('watchers.defaults.digest_max_chars', 800));
    }
}
