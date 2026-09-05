<?php

namespace App\Services\Telegram\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class TelegramSendException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $telegramErrorCode = null,
        public readonly ?string $telegramDescription = null,
        public readonly string $errorClass = 'other',
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $message = 'Telegram send failed'): self
    {
        $description = (string) ($response->json('description') ?? $response->body());
        $code = $response->json('error_code');
        $code = is_numeric($code) ? (int) $code : $response->status();
        $class = self::classify($description, $code);

        return new self(
            $message,
            $code,
            $description !== '' ? $description : null,
            $class,
        );
    }

    public static function classify(string $description, int $code): string
    {
        $haystack = mb_strtolower($description);

        if (str_contains($haystack, 'kicked') || str_contains($haystack, 'bot is not a member')) {
            return 'kicked';
        }

        if (str_contains($haystack, 'chat not found')) {
            return 'not_found';
        }

        if (str_contains($haystack, 'not enough rights') || str_contains($haystack, 'have no rights') || str_contains($haystack, 'need administrator')) {
            return 'rights';
        }

        if ($code === 403 || str_contains($haystack, 'forbidden')) {
            return 'forbidden';
        }

        return 'other';
    }
}
