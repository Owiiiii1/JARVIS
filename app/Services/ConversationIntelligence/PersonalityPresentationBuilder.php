<?php

namespace App\Services\ConversationIntelligence;

use App\Models\User;
use App\Services\Assistant\AssistantProfileService;
use Throwable;

final class PersonalityPresentationBuilder
{
    public function __construct(
        private readonly AssistantProfileService $profiles,
    ) {}

    public function build(User $user, ?WorkingContext $working = null, ?string $spokenStyleHint = null): string
    {
        try {
            $identity = $this->profiles->identityContext($user);
        } catch (Throwable) {
            $identity = 'Assistant identity is unavailable for this turn. Continue with the platform prompt.';
        }

        $lines = [
            $identity,
            'This identity is the single personality source for Web, Voice, and Telegram. Do not invent a channel-specific persona.',
            'Keep formality, verbosity, address, and temperament stable across nearby turns unless the user explicitly changes them.',
        ];

        if ($working?->temporaryStyle !== null && $working->temporaryStyle !== '') {
            $lines[] = 'Temporary conversation style (not a profile write): '.$this->styleLine($working->temporaryStyle);
        }

        if ($spokenStyleHint !== null && trim($spokenStyleHint) !== '') {
            $lines[] = 'Voice presentation hint only (same personality): '.trim($spokenStyleHint);
        }

        return implode("\n", $lines);
    }

    private function styleLine(string $style): string
    {
        return match ($style) {
            'short' => 'answer briefly for the rest of this conversation until told otherwise',
            'detailed' => 'answer in more detail for the rest of this conversation until told otherwise',
            'italian' => 'reply in Italian for the rest of this conversation until told otherwise',
            'english' => 'reply in English for the rest of this conversation until told otherwise',
            'russian' => 'reply in Russian for the rest of this conversation until told otherwise',
            default => $style,
        };
    }
}
