<?php

namespace App\Enums;

enum AiRoleKey: string
{
    case OwnerConversation = 'owner_conversation';
    case OwnerAnalysis = 'owner_analysis';
    case UserConversation = 'user_conversation';

    public function label(): string
    {
        return match ($this) {
            self::OwnerConversation => 'Owner Conversation AI',
            self::OwnerAnalysis => 'Owner Analysis AI',
            self::UserConversation => 'Default User Conversation AI',
        };
    }

    public function isConversation(): bool
    {
        return $this !== self::OwnerAnalysis;
    }
}
