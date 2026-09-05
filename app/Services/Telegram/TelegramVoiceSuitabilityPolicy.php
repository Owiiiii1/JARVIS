<?php

namespace App\Services\Telegram;

final class TelegramVoiceSuitabilityPolicy
{
    public function __construct(
        private readonly SpokenTextNormalizer $normalizer,
        private readonly int $maxSpokenChars = 2000,
        private readonly int $maxCodeFenceChars = 400,
        private readonly int $maxTableRows = 4,
    ) {}

    public function evaluate(string $canonical): TelegramVoiceSuitability
    {
        $canonical = trim($canonical);

        if ($canonical === '') {
            return new TelegramVoiceSuitability(false, '', 'empty');
        }

        if ($this->codeFenceChars($canonical) > $this->maxCodeFenceChars) {
            return new TelegramVoiceSuitability(false, '', 'code_block');
        }

        if ($this->markdownTableRows($canonical) >= $this->maxTableRows) {
            return new TelegramVoiceSuitability(false, '', 'large_table');
        }

        if (preg_match('/```artifact\b/i', $canonical) === 1) {
            return new TelegramVoiceSuitability(false, '', 'artifact');
        }

        $spoken = $this->normalizer->normalize($canonical);

        if ($spoken === '') {
            return new TelegramVoiceSuitability(false, '', 'empty_spoken');
        }

        if (mb_strlen($spoken) > $this->maxSpokenChars) {
            return new TelegramVoiceSuitability(false, $spoken, 'too_long');
        }

        return new TelegramVoiceSuitability(true, $spoken);
    }

    private function codeFenceChars(string $text): int
    {
        if (preg_match_all('/```[\s\S]*?```/', $text, $matches) === false) {
            return 0;
        }

        $total = 0;

        foreach ($matches[0] as $block) {
            $total += mb_strlen((string) $block);
        }

        return $total;
    }

    private function markdownTableRows(string $text): int
    {
        $rows = 0;

        foreach (preg_split("/\n/", $text) ?: [] as $line) {
            if (preg_match('/^\s*\|.+\|\s*$/', $line) === 1 && ! preg_match('/^\s*\|?\s*:?-{3,}/', $line)) {
                $rows++;
            }
        }

        return $rows;
    }
}
