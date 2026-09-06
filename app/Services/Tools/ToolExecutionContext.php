<?php

namespace App\Services\Tools;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Context\TurnBudgetTracker;
use App\Services\ConversationIntelligence\WorkingContext;

final readonly class ToolExecutionContext
{
    public function __construct(
        public User $user,
        public Conversation $conversation,
        public ?Message $inbound = null,
        public ?string $channel = null,
        public ?bool $explicitUserCommand = null,
        public ?string $confirmationIntent = null,
        public bool $bypassConfirmation = false,
        public TurnBudgetTracker $budgets = new TurnBudgetTracker,
        public ?WorkingContext $working = null,
    ) {}
}
