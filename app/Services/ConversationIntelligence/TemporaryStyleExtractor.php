<?php

namespace App\Services\ConversationIntelligence;

final class TemporaryStyleExtractor
{
    /**
     * @param  list<string>  $recentUserTexts  newest last
     */
    public function extract(array $recentUserTexts): ?string
    {
        for ($i = count($recentUserTexts) - 1; $i >= 0; $i--) {
            $style = $this->fromText((string) $recentUserTexts[$i]);

            if ($style !== null) {
                return $style;
            }
        }

        return null;
    }

    public function fromText(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/отвечай( сейчас)?( максимально)? (коротко|кратко)|говори кратко|be (more )?concise|answer (more )?briefly/u', $normalized) === 1) {
            return 'short';
        }

        if (preg_match('/говори(те)? (более )?подробн|развёрнут|развернут|be (more )?detailed|answer (in )?detail/u', $normalized) === 1) {
            return 'detailed';
        }

        if (preg_match('/по-итальянски|in italian|на итальянском/u', $normalized) === 1) {
            return 'italian';
        }

        if (preg_match('/(на английском|in english|по-английски)/u', $normalized) === 1) {
            return 'english';
        }

        if (preg_match('/по-русски|на русском|in russian/u', $normalized) === 1) {
            return 'russian';
        }

        return null;
    }
}
