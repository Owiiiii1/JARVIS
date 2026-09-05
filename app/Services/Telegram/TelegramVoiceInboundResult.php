<?php

namespace App\Services\Telegram;

use App\Services\Conversations\ConversationTurnResult;

final readonly class TelegramVoiceInboundResult
{
    public function __construct(
        public TelegramVoiceInboundStatus $status,
        public ?ConversationTurnResult $turn = null,
        public ?string $userText = null,
        public string $reason = '',
    ) {}

    public static function duplicate(): self
    {
        return new self(TelegramVoiceInboundStatus::Duplicate, reason: 'duplicate');
    }

    public static function ignored(string $reason): self
    {
        return new self(TelegramVoiceInboundStatus::Ignored, reason: $reason);
    }

    public static function turn(ConversationTurnResult $turn): self
    {
        return new self(TelegramVoiceInboundStatus::Turn, turn: $turn);
    }

    public static function notice(string $userText, string $reason): self
    {
        return new self(TelegramVoiceInboundStatus::UserNotice, userText: $userText, reason: $reason);
    }
}
