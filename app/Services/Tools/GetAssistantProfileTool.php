<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Users\UserCapability;

final class GetAssistantProfileTool implements JarvisTool
{
    public const NAME = 'get_assistant_profile';

    public function __construct(
        private readonly AssistantProfileService $profiles,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads the current user’s assistant personalization profile: name, personality, interaction style, about_user, and onboarding status. Use when the user asks how they named you, what you know from onboarding, or current assistant style. Never pass user_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => new \stdClass,
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::CHAT,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::CHAT);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $payload = $this->profiles->workspacePayload($context->user);
        unset($payload['show_onboarding'], $payload['presentation_name']);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$payload,
        ]);
    }
}
