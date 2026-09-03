<?php

namespace App\Services\Memory\DTO;

use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\UserProfile;

final readonly class MemoryContextPackage
{
    /**
     * @param  list<Memory>  $memories
     * @param  list<ConversationSummary>  $crossChatSummaries
     */
    public function __construct(
        public array $memories = [],
        public array $crossChatSummaries = [],
        public ?ConversationSummary $currentSummary = null,
        public ?UserProfile $profile = null,
    ) {}
}
