<?php

namespace App\Services\Telegram\Pairing;

final readonly class TelegramInboundContext
{
    public function __construct(
        public string $externalUserId,
        public string $externalChatId,
        public ?string $username,
        public ?string $firstName,
        public ?string $lastName,
    ) {}

    /**
     * @param  array{
     *     external_user_id: string,
     *     external_chat_id: string,
     *     username?: string|null,
     *     first_name?: string|null,
     *     last_name?: string|null
     * }  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            externalUserId: $payload['external_user_id'],
            externalChatId: $payload['external_chat_id'],
            username: $payload['username'] ?? null,
            firstName: $payload['first_name'] ?? null,
            lastName: $payload['last_name'] ?? null,
        );
    }
}
