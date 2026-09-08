<?php

namespace App\Services\Conversations;

final class TurnIsolation
{
    /**
     * A new user message is a new execution turn. Previous tool loops do not continue.
     *
     * @return list<string>
     */
    public static function policyLines(): array
    {
        return [
            'Each new user message starts a new execution turn. Previous tool plans, retry state, and incomplete tool loops do not continue automatically.',
            'Conversation history remains context, but you must not resume an unfinished Storage/Gmail/Calendar/GitHub/read loop unless the current message clearly asks to continue that same analysis.',
            'Short presence checks such as «эй», «ты тут?», «hello» are ordinary replies. Answer them briefly. Do not call tools and do not continue the previous file or mail analysis.',
            '«Повтори предыдущий» means restate the previous semantic answer or limitation. It does not mean blindly replaying a stale tool plan.',
        ];
    }

    public static function isPresenceCheck(?string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'эй', 'эй?', 'эй!', 'але', 'алло', 'ало',
            'ты тут', 'ты тут?', 'ты здесь', 'ты здесь?',
            'привет', 'hello', 'hi', 'hey', 'ping',
            'you there', 'you there?', 'are you there', 'are you there?',
        ], true)) {
            return true;
        }

        return preg_match('/^(эй+|hey+|hi+|hello)\s*[?!.]*$/u', $normalized) === 1
            || preg_match('/^ты\s+(тут|здесь|на месте)\s*[?!.]*$/u', $normalized) === 1;
    }

    public static function isRepeatRequest(?string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return false;
        }

        return preg_match('/\b(повтори|повторить|еще раз|ещё раз|repeat|say that again|скажи ещё раз|скажи еще раз)\b/u', $normalized) === 1;
    }

    public static function presenceHint(): string
    {
        return 'This user message is a short presence check. Reply briefly that you are here. Do not call tools. Do not resume previous file, mail, calendar, or GitHub analysis.';
    }

    public static function repeatHint(): string
    {
        return 'The user asked to repeat the previous answer. Restate the last semantic reply or its limitation. Do not restart a stale tool loop unless the previous turn never produced an answer and the user clearly wants the same analysis again.';
    }

    private static function normalize(?string $text): string
    {
        $trimmed = trim((string) $text);

        if ($trimmed === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return mb_strtolower(is_string($collapsed) ? $collapsed : $trimmed);
    }
}
