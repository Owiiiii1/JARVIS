<?php

namespace App\Services\Ai\Contracts;

use App\Models\AiRoleSetting;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;

interface AiChatGateway
{
    public function chat(AiRoleSetting $configuration, AiChatRequest $request): AiChatResponse;

    public function supportsTools(AiRoleSetting $configuration): bool;

    public function supportsVision(AiRoleSetting $configuration): bool;
}
