<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Assistant\AssistantProfileException;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Users\UserCapability;

final class CompleteAssistantOnboardingTool implements JarvisTool
{
    public const NAME = 'complete_assistant_onboarding';

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
            description: 'Marks assistant onboarding complete for the current user after assistant_name, personality, interaction_style, and about_user are set and a brief summary was given. Do not call early. Never pass user_id.',
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
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::CHAT);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $profile = $this->profiles->completeOnboarding($context->user);
        } catch (AssistantProfileException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'missing' => $exception->missing,
            ]);
        }

        $payload = $this->profiles->toArray($profile, $context->user);
        unset($payload['show_onboarding'], $payload['presentation_name']);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$payload,
        ]);
    }
}
