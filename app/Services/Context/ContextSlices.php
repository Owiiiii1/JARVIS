<?php

namespace App\Services\Context;

use App\Services\Ai\DTO\AiChatMessage;

final class ContextSlices
{
    /**
     * @param  list<string>  $memoryLines
     * @param  list<string>  $crossChatLines
     * @param  list<AiChatMessage>  $recentMessages
     */
    public function __construct(
        public string $platformPrompt,
        public ?string $generalPrompt,
        public ?string $applicationEvent,
        public ?string $currentSummary,
        public ?string $profile,
        public array $memoryLines,
        public array $crossChatLines,
        public array $recentMessages,
        public bool $lastIsCurrentTurn,
        public ?string $projectsBlock = null,
    ) {}
}
