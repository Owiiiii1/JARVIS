<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Users\UserCapability;

final class ConfirmToolActionTool implements JarvisTool
{
    public const NAME = 'confirm_tool_action';

    public function __construct(
        private readonly ToolConfirmationService $confirmations,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Confirms the current pending tool action after the user has already said yes. Available only when a pending confirmation exists. Do not call this unless the latest user message is an explicit confirmation. Do not invent confirmation_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'confirmation_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional pending confirmation public id from the previous tool result.',
                    ],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::CHAT,
            operation: ToolOperationClass::Write,
            confirmationHint: 'The user must confirm the pending action.',
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $this->confirmations->hasPending($context->user, $context->conversation);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        if ($context->confirmationIntent !== ConfirmationIntentParser::CONFIRM) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'confirmation_not_affirmed',
            ]);
        }

        $publicId = trim((string) ($call->arguments['confirmation_id'] ?? ''));
        $pending = $publicId !== ''
            ? $this->confirmations->findOwnedPending($context->user, $context->conversation, $publicId)
            : $this->confirmations->latestPending($context->user, $context->conversation);

        if ($pending === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'confirmation_not_found',
            ]);
        }

        return $this->confirmations->executeConfirmed($pending, $context, app(ToolRegistry::class));
    }
}
