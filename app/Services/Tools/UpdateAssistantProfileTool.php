<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Users\UserCapability;

final class UpdateAssistantProfileTool implements JarvisTool
{
    public const NAME = 'update_assistant_profile';

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
            description: 'Updates the current user’s assistant personalization. Call only when the user explicitly states a preference or clearly answers an onboarding question. Send only fields that were provided. Do not invent values. Do not pass user_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'assistant_name' => [
                        'type' => 'STRING',
                        'description' => 'How the user wants to call the assistant.',
                    ],
                    'personality' => [
                        'type' => 'STRING',
                        'description' => 'Character of the assistant: tone, humor, formality, verbosity.',
                    ],
                    'interaction_style' => [
                        'type' => 'STRING',
                        'description' => 'How the assistant should work with the user: clarify vs assume, proactivity, concision, languages.',
                    ],
                    'about_user' => [
                        'type' => 'STRING',
                        'description' => 'Compact onboarding summary about the user. Not a full memory dump.',
                    ],
                ],
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
        $allowed = ['assistant_name', 'personality', 'interaction_style', 'about_user'];
        $fields = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $call->arguments) && $call->arguments[$key] !== null) {
                $fields[$key] = $call->arguments[$key];
            }
        }

        if ($fields === []) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $profile = $this->profiles->updateFields($context->user, $fields);
        $payload = $this->profiles->toArray($profile, $context->user);
        unset($payload['show_onboarding'], $payload['presentation_name']);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'updated' => array_keys($fields),
            ...$payload,
        ]);
    }
}
