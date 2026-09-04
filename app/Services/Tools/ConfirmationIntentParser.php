<?php

namespace App\Services\Tools;

final class ConfirmationIntentParser
{
    public const CONFIRM = 'confirm';

    public const CANCEL = 'cancel';

    public function parse(?string $body): ?string
    {
        $normalized = $this->normalize($body);

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['да', 'yes', 'confirm', 'подтверждаю', 'удалить', 'удали'], true)) {
            return self::CONFIRM;
        }

        if (in_array($normalized, ['нет', 'no', 'cancel', 'отмена', 'отменить'], true)) {
            return self::CANCEL;
        }

        return null;
    }

    private function normalize(?string $body): string
    {
        $text = trim((string) $body);
        $text = preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $text) ?? $text;

        return mb_strtolower($text);
    }
}
