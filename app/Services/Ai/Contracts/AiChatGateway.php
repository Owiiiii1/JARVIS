<?php

namespace App\Services\Ai\Contracts;

use App\Models\AiRoleSetting;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;

interface AiChatGateway
{
    public function chat(AiRoleSetting $configuration, AiChatRequest $request): AiChatResponse;
}
